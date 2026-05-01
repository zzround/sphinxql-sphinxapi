<?php

/////////////////////////////////////////////////////////////////////////////
// PHP version of Sphinx searchd client (PHP API)
// Rewritten to use SphinxQL (MySQL protocol) instead of native binary protocol
/////////////////////////////////////////////////////////////////////////////

/// known searchd status codes
const SEARCHD_OK      = 0;
const SEARCHD_ERROR   = 1;
const SEARCHD_RETRY   = 2;
const SEARCHD_WARNING = 3;

/// known ranking modes (ext2 only)
const SPH_RANK_PROXIMITY_BM15 = 0;
const SPH_RANK_BM15           = 1;
const SPH_RANK_NONE           = 2;
const SPH_RANK_WORDCOUNT      = 3;
const SPH_RANK_PROXIMITY      = 4;
const SPH_RANK_MATCHANY       = 5;
const SPH_RANK_FIELDMASK      = 6;
const SPH_RANK_SPH04          = 7;
const SPH_RANK_EXPR           = 8;
const SPH_RANK_TOTAL          = 9;

/// known sort modes
const SPH_SORT_RELEVANCE     = 0;
const SPH_SORT_ATTR_DESC     = 1;
const SPH_SORT_ATTR_ASC      = 2;
const SPH_SORT_TIME_SEGMENTS = 3;
const SPH_SORT_EXTENDED      = 4;

/// known filter types
const SPH_FILTER_VALUES      = 0;
const SPH_FILTER_RANGE       = 1;
const SPH_FILTER_FLOATRANGE  = 2;
const SPH_FILTER_STRING      = 3;
const SPH_FILTER_STRING_LIST = 6;

/// known attribute types
const SPH_ATTR_INTEGER = 1;
const SPH_ATTR_BOOL    = 4;
const SPH_ATTR_FLOAT   = 5;
const SPH_ATTR_BIGINT  = 6;
const SPH_ATTR_STRING  = 7;
const SPH_ATTR_FACTORS = 1001;
const SPH_ATTR_MULTI   = 0x40000001;
const SPH_ATTR_MULTI64 = 0x40000002;

/// known grouping functions
const SPH_GROUPBY_DAY      = 0;
const SPH_GROUPBY_WEEK     = 1;
const SPH_GROUPBY_MONTH    = 2;
const SPH_GROUPBY_YEAR     = 3;
const SPH_GROUPBY_ATTR     = 4;
const SPH_GROUPBY_ATTRPAIR = 5;

/// known update types
const SPH_UPDATE_PLAIN  = 0;
const SPH_UPDATE_MVA    = 1;
const SPH_UPDATE_STRING = 2;
const SPH_UPDATE_JSON   = 3;

function sphFixUint($value)
{
    if (PHP_INT_SIZE >= 8) {
        if ($value < 0) {
            $value += (1 << 32);
        }
        return $value;
    } else {
        return sprintf("%u", $value);
    }
}

function sphSetBit($flag, $bit, $on)
{
    if ($on) {
        $flag |= (1 << $bit);
    } else {
        $reset = 16777215 ^ (1 << $bit);
        $flag  = $flag & $reset;
    }
    return $flag;
}

/// sphinx searchd client class
class SphinxClient
{
    public $_host;
    public $_port;
    public $_offset;
    public $_limit;
    public $_sort;
    public $_sortby;
    public $_min_id;
    public $_max_id;
    public $_filters;
    public $_groupby;
    public $_groupfunc;
    public $_groupsort;
    public $_groupdistinct;
    public $_maxmatches;
    public $_cutoff;
    public $_retrycount;
    public $_retrydelay;
    public $_indexweights;
    public $_ranker;
    public $_rankexpr;
    public $_maxquerytime;
    public $_fieldweights;
    public $_select;
    public $_query_flags;
    public $_predictedtime;
    public $_outerorderby;
    public $_outeroffset;
    public $_outerlimit;
    public $_hasouter;
    public $_token_filter_library;
    public $_token_filter_name;
    public $_token_filter_opts;

    public $_error;
    public $_warning;
    public $_connerror;

    public $_reqs;
    public $_mbenc;
    public $_arrayresult;
    public $_timeout;

    private $_path         = false;
    private $_conn         = null;
    private $_persistent   = false;
    private $_schema_cache = [];

    public function __construct()
    {
        $this->_host = "localhost";
        $this->_port = 9306;

        $this->_offset               = 0;
        $this->_limit                = 20;
        $this->_sort                 = SPH_SORT_RELEVANCE;
        $this->_sortby               = "";
        $this->_min_id               = 0;
        $this->_max_id               = 0;
        $this->_filters              = [];
        $this->_groupby              = "";
        $this->_groupfunc            = SPH_GROUPBY_DAY;
        $this->_groupsort            = "@group desc";
        $this->_groupdistinct        = "";
        $this->_maxmatches           = 1000;
        $this->_cutoff               = 0;
        $this->_retrycount           = 0;
        $this->_retrydelay           = 0;
        $this->_indexweights         = [];
        $this->_ranker               = SPH_RANK_PROXIMITY_BM15;
        $this->_rankexpr             = "";
        $this->_maxquerytime         = 0;
        $this->_fieldweights         = [];
        $this->_select               = "*";
        $this->_query_flags          = sphSetBit(0, 6, true);
        $this->_predictedtime        = 0;
        $this->_outerorderby         = "";
        $this->_outeroffset          = 0;
        $this->_outerlimit           = 0;
        $this->_hasouter             = false;
        $this->_token_filter_library = '';
        $this->_token_filter_name    = '';
        $this->_token_filter_opts    = '';

        $this->_error     = "";
        $this->_warning   = "";
        $this->_connerror = false;

        $this->_reqs        = [];
        $this->_mbenc       = "";
        $this->_arrayresult = false;
        $this->_timeout     = 0;
    }

    public function __destruct()
    {
        $this->_conn = null;
    }

    public function GetLastError()
    {
        return $this->_error;
    }

    public function GetLastWarning()
    {
        return $this->_warning;
    }

    public function IsConnectError()
    {
        return $this->_connerror;
    }

    public function SetServer($host, $port = 0)
    {
        assert(is_string($host));
        if ($host[0] == '/') {
            $this->_path         = 'unix://' . $host;
            $this->_schema_cache = [];
            return;
        }
        if (str_starts_with($host, "unix://")) {
            $this->_path         = $host;
            $this->_schema_cache = [];
            return;
        }

        $this->_host = $host;
        $port        = intval($port);
        assert(0 <= $port && $port < 65536);
        $this->_port         = ($port == 0) ? 9306 : $port;
        $this->_path         = '';
        $this->_schema_cache = [];
    }

    public function SetConnectTimeout($timeout)
    {
        assert(is_numeric($timeout));
        $this->_timeout = $timeout;
    }

    public function _Connect()
    {
        if ($this->_conn !== null) {
            if ($this->_persistent) {
                try {
                    $this->_conn->query("SELECT 1");
                    return $this->_conn;
                } catch (PDOException $e) {
                    $this->_conn = null;
                }
            } else {
                return $this->_conn;
            }
        }

        $this->_connerror = false;

        if ($this->_path) {
            $socket_path = $this->_path;
            if (str_starts_with($socket_path, "unix://")) {
                $socket_path = substr($socket_path, 7);
            }
            $dsn = "mysql:unix_socket=" . $socket_path;
        } else {
            $dsn = "mysql:host=" . $this->_host . ";port=" . $this->_port;
        }

        try {
            $options = [];
            if ($this->_timeout > 0) {
                $options[PDO::ATTR_TIMEOUT] = $this->_timeout;
            }
            $this->_conn = new PDO($dsn, "", "", $options);
            $this->_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->_conn->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
            $this->_conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        } catch (PDOException $e) {
            $location         = $this->_path ?: "$this->_host:$this->_port";
            $this->_error     = "connection to $location failed (" . $e->getMessage() . ")";
            $this->_connerror = true;
            return false;
        }

        return $this->_conn;
    }

    private function _DisconnectAfterQuery()
    {
        if (! $this->_persistent) {
            $this->_conn = null;
        }
    }

    public function _MBPush()
    {
        $this->_mbenc = "";
        if (ini_get("mbstring.func_overload") & 2) {
            $this->_mbenc = mb_internal_encoding();
            mb_internal_encoding("latin1");
        }
    }

    public function _MBPop()
    {
        if ($this->_mbenc) {
            mb_internal_encoding($this->_mbenc);
        }
    }

    private function _quoteAttr($name)
    {
        return "`" . str_replace("`", "``", $name) . "`";
    }

    private function _quoteIndex($index)
    {
        $parts = array_map('trim', explode(',', $index));
        return implode(', ', array_map(function ($p) {
            return $this->_quoteAttr($p);
        }, $parts));
    }

    private function _pdoQuote($value)
    {
        if ($this->_conn !== null) {
            $quoted = $this->_conn->quote(strval($value));
            if ($quoted !== false) {
                return $quoted;
            }
        }
        return "'" . addslashes(strval($value)) . "'";
    }

    private function _IsConnectionError(PDOException $e)
    {
        $code       = strval($e->getCode());
        $conn_codes = ['08S01', 'HY000', '2006', '2013'];
        if (in_array($code, $conn_codes)) {
            return true;
        }
        $msg = $e->getMessage();
        if (stripos($msg, 'lost connection') !== false || stripos($msg, 'server has gone away') !== false) {
            return true;
        }
        return false;
    }

    private function _buildGroupByExpr()
    {
        $attr = $this->_quoteAttr($this->_groupby);
        switch ($this->_groupfunc) {
            case SPH_GROUPBY_DAY: return "FLOOR(" . $attr . "/86400)";
            case SPH_GROUPBY_WEEK: return "FLOOR(" . $attr . "/604800)";
            case SPH_GROUPBY_MONTH: return "FLOOR(" . $attr . "/2678400)";
            case SPH_GROUPBY_YEAR: return "FLOOR(" . $attr . "/31536000)";
            case SPH_GROUPBY_ATTR: return $attr;
            case SPH_GROUPBY_ATTRPAIR: return $attr;
            default: return $attr;
        }
    }

    private function _buildOrderBy()
    {
        switch ($this->_sort) {
            case SPH_SORT_RELEVANCE: return "";
            case SPH_SORT_ATTR_DESC: return $this->_quoteAttr($this->_sortby) . " DESC";
            case SPH_SORT_ATTR_ASC: return $this->_quoteAttr($this->_sortby) . " ASC";
            case SPH_SORT_TIME_SEGMENTS: return $this->_buildTimeSegmentsOrder();
            case SPH_SORT_EXTENDED: return $this->_sortby;
            default: return "";
        }
    }

    private function _buildTimeSegmentsOrder()
    {
        $attr = $this->_quoteAttr($this->_sortby);
        $now  = time();
        $h    = $now - 3600;
        $d    = $now - 86400;
        $w    = $now - 604800;
        $m    = $now - 2592000;
        $y    = $now - 31536000;
        return "INTERVAL(" . $attr . ", $y, $m, $w, $d, $h) ASC, WEIGHT() DESC";
    }

    private function _translateGroupSort()
    {
        $sort = $this->_groupsort;
        $sort = str_replace("@group", "@groupby", $sort);
        $sort = str_replace("@count", "COUNT(*)", $sort);
        $sort = str_replace("@weight", "WEIGHT()", $sort);
        if ($this->_groupdistinct !== "") {
            $sort = str_replace("@distinct", "COUNT(DISTINCT " . $this->_quoteAttr($this->_groupdistinct) . ")", $sort);
        }
        return $sort;
    }

    private function _buildOptions($comment)
    {
        $opts = [];

        $ranker_names = [
            SPH_RANK_PROXIMITY_BM15 => "proximity_bm15",
            SPH_RANK_BM15           => "bm15",
            SPH_RANK_NONE           => "none",
            SPH_RANK_WORDCOUNT      => "wordcount",
            SPH_RANK_PROXIMITY      => "proximity",
            SPH_RANK_MATCHANY       => "matchany",
            SPH_RANK_FIELDMASK      => "fieldmask",
            SPH_RANK_SPH04          => "sph04",
            SPH_RANK_EXPR           => "expr",
        ];
        if (isset($ranker_names[$this->_ranker])) {
            if ($this->_ranker == SPH_RANK_EXPR) {
                $opts[] = "ranker=expr('" . addslashes($this->_rankexpr) . "')";
            } else {
                $opts[] = "ranker=" . $ranker_names[$this->_ranker];
            }
        }

        if (! empty($this->_fieldweights)) {
            $pairs = [];
            foreach ($this->_fieldweights as $field => $weight) {
                $pairs[] = $field . "=" . intval($weight);
            }
            $opts[] = "field_weights=(" . implode(", ", $pairs) . ")";
        }

        if (! empty($this->_indexweights)) {
            $pairs = [];
            foreach ($this->_indexweights as $idx => $weight) {
                $pairs[] = $idx . "=" . intval($weight);
            }
            $opts[] = "index_weights=(" . implode(", ", $pairs) . ")";
        }

        if ($this->_maxquerytime > 0) {
            $opts[] = "max_query_time=" . intval($this->_maxquerytime);
        }

        if ($this->_maxmatches != 1000) {
            $opts[] = "max_matches=" . intval($this->_maxmatches);
        }

        if ($this->_cutoff > 0) {
            $opts[] = "cutoff=" . intval($this->_cutoff);
        }

        if ($this->_retrycount > 0) {
            $opts[] = "retry_count=" . intval($this->_retrycount);
            if ($this->_retrydelay > 0) {
                $opts[] = "retry_delay=" . intval($this->_retrydelay);
            }
        }

        if ($this->_query_flags & 1) {
            $opts[] = "reverse_scan=1";
        }

        if ($this->_query_flags & 2) {
            $opts[] = "sort_method='kbuffer'";
        }

        if ($this->_query_flags & 4) {
            $opts[] = "max_predicted_time=" . intval($this->_predictedtime);
        }

        if ($this->_query_flags & 8) {
            $opts[] = "boolean_simplify=1";
        }

        $idf_flags = [];
        if ($this->_query_flags & 16) {
            $idf_flags[] = "plain";
        }

        if (($this->_query_flags >> 6) & 1) {
            $idf_flags[] = "tfidf_normalized";
        }

        if (($this->_query_flags >> 7) & 1) {
            $idf_flags[] = "tfidf_unnormalized";
        }

        if (! empty($idf_flags)) {
            $opts[] = "idf='" . implode(",", $idf_flags) . "'";
        }

        if ($this->_query_flags & 32) {
            $opts[] = "global_idf=1";
        }

        if ($this->_query_flags & 256) {
            $opts[] = "low_priority=1";
        }

        if ($this->_query_flags & 1024) {
            $opts[] = "lax_agent_errors=1";
        }

        if (strlen($comment) > 0) {
            $opts[] = "comment=" . $this->_pdoQuote($comment);
        }

        return $opts;
    }

    private function _BuildSphinxQL($query, $index, $comment)
    {
        $parts = [];

        $select = $this->_select;
        if ($this->_groupdistinct !== "") {
            $select .= ", COUNT(DISTINCT " . $this->_quoteAttr($this->_groupdistinct) . ") AS `@distinct`";
        }
        $parts[] = "SELECT " . $select;

        $parts[] = "FROM " . $this->_quoteIndex($index);

        $where = [];

        if (strlen($query) > 0) {
            $where[] = "MATCH(" . $this->_pdoQuote($query) . ")";
        }

        if ($this->_min_id > 0 || $this->_max_id > 0) {
            if ($this->_min_id > 0 && $this->_max_id > 0) {
                $where[] = "id BETWEEN " . intval($this->_min_id) . " AND " . intval($this->_max_id);
            } elseif ($this->_min_id > 0) {
                $where[] = "id >= " . intval($this->_min_id);
            } else {
                $where[] = "id <= " . intval($this->_max_id);
            }
        }

        foreach ($this->_filters as $filter) {
            $attr    = $this->_quoteAttr($filter["attr"]);
            $exclude = ! empty($filter["exclude"]);

            switch ($filter["type"]) {
                case SPH_FILTER_VALUES:
                    $vals    = implode(",", array_map('intval', $filter["values"]));
                    $where[] = $attr . ($exclude ? " NOT IN (" : " IN (") . $vals . ")";
                    break;

                case SPH_FILTER_RANGE:
                    $cond    = $attr . " BETWEEN " . intval($filter["min"]) . " AND " . intval($filter["max"]);
                    $where[] = $exclude ? "NOT (" . $cond . ")" : $cond;
                    break;

                case SPH_FILTER_FLOATRANGE:
                    $cond    = $attr . " BETWEEN " . floatval($filter["min"]) . " AND " . floatval($filter["max"]);
                    $where[] = $exclude ? "NOT (" . $cond . ")" : $cond;
                    break;

                case SPH_FILTER_STRING:
                    $cond    = $attr . " = " . $this->_pdoQuote($filter["value"]);
                    $where[] = $exclude ? "NOT (" . $cond . ")" : $cond;
                    break;

                case SPH_FILTER_STRING_LIST:
                    $vals = implode(",", array_map(function ($v) {
                        return $this->_pdoQuote($v);
                    }, $filter["values"]));
                    $where[] = $attr . ($exclude ? " NOT IN (" : " IN (") . $vals . ")";
                    break;
            }
        }

        if (! empty($where)) {
            $parts[] = "WHERE " . implode(" AND ", $where);
        }

        if ($this->_groupby !== "") {
            $parts[] = "GROUP BY " . $this->_buildGroupByExpr();

            if ($this->_sort !== SPH_SORT_RELEVANCE && $this->_sortby !== "") {
                $within  = str_replace("@weight", "WEIGHT()", $this->_sortby);
                $parts[] = "WITHIN GROUP ORDER BY " . $within;
            }
        }

        if ($this->_groupby !== "") {
            $groupsort = $this->_translateGroupSort();
            if ($groupsort !== "") {
                $parts[] = "ORDER BY " . $groupsort;
            }
        } else {
            $orderby = $this->_buildOrderBy();
            if ($orderby !== "") {
                $parts[] = "ORDER BY " . $orderby;
            }
        }

        if ($this->_offset > 0) {
            $parts[] = "LIMIT " . intval($this->_offset) . ", " . intval($this->_limit);
        } else {
            $parts[] = "LIMIT " . intval($this->_limit);
        }

        $options = $this->_buildOptions($comment);
        if (! empty($options)) {
            $parts[] = "OPTION " . implode(", ", $options);
        }

        $sql = implode(" ", $parts);

        if ($this->_hasouter) {
            $sql = "SELECT * FROM (" . $sql . ") AS inner_q";
            if (strlen($this->_outerorderby) > 0) {
                $sql .= " ORDER BY " . $this->_outerorderby;
            }
            $sql .= " LIMIT " . intval($this->_outeroffset) . ", " . intval($this->_outerlimit);
        }

        return $sql;
    }

    private function _MapAttrType($type)
    {
        $type = strtolower(trim($type));
        if (strpos($type, 'bigint') !== false) {
            return SPH_ATTR_BIGINT;
        }

        if (strpos($type, 'float') !== false) {
            return SPH_ATTR_FLOAT;
        }

        if (strpos($type, 'bool') !== false) {
            return SPH_ATTR_BOOL;
        }

        if (strpos($type, 'string') !== false) {
            return SPH_ATTR_STRING;
        }

        if (strpos($type, 'json') !== false) {
            return SPH_ATTR_STRING;
        }

        if (strpos($type, 'multi') !== false) {
            return SPH_ATTR_MULTI;
        }

        if (strpos($type, 'mva') !== false) {
            return SPH_ATTR_MULTI;
        }

        if (strpos($type, 'integer') !== false || strpos($type, 'int') !== false) {
            return SPH_ATTR_INTEGER;
        }
        if (strpos($type, 'timestamp') !== false) {
            return SPH_ATTR_INTEGER;
        }

        return SPH_ATTR_INTEGER;
    }

    private function _GetIndexSchema($conn, $index)
    {
        $idx = trim(explode(',', $index)[0]);
        if (isset($this->_schema_cache[$idx])) {
            return $this->_schema_cache[$idx];
        }

        $fields = [];
        $attrs  = [];

        try {
            $stmt = $conn->query("DESCRIBE " . $this->_quoteAttr($idx));
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    $name = $row[0];
                    $type = $row[1];

                    if (stripos($type, 'field') !== false) {
                        $fields[] = $name;
                    } else {
                        $attrs[$name] = $this->_MapAttrType($type);
                    }
                }
            }
        } catch (PDOException $e) {
            // if DESCRIBE fails, proceed with empty schema
        }

        $this->_schema_cache[$idx] = ['fields' => $fields, 'attrs' => $attrs];
        return $this->_schema_cache[$idx];
    }

    private function _TransformRows($rows, $schema)
    {
        $matches   = [];
        $idx       = 0;
        $weightKey = null;

        foreach ($rows as $row) {
            if ($weightKey === null) {
                foreach (['weight()', 'Weight()', 'WEIGHT()', 'weight'] as $candidate) {
                    if (isset($row[$candidate])) {
                        $weightKey = $candidate;
                        break;
                    }
                }
                if ($weightKey === null) {
                    $weightKey = 'weight()';
                }
            }

            $doc = isset($row['id']) ? $row['id'] : 0;
            $doc = sphFixUint(intval($doc));

            $weight = isset($row[$weightKey]) ? $row[$weightKey] : 1;
            if (is_string($weight)) {
                $weight = intval($weight);
            }
            $weight = sprintf("%u", $weight);

            $attrvals = [];
            foreach ($schema['attrs'] as $attrName => $attrType) {
                if (! array_key_exists($attrName, $row)) {
                    continue;
                }
                $val = $row[$attrName];

                switch ($attrType) {
                    case SPH_ATTR_FLOAT:
                        $attrvals[$attrName] = floatval($val);
                        break;
                    case SPH_ATTR_BIGINT:
                        $attrvals[$attrName] = $val;
                        break;
                    case SPH_ATTR_BOOL:
                        $attrvals[$attrName] = intval($val) ? 1 : 0;
                        break;
                    case SPH_ATTR_STRING:
                        $attrvals[$attrName] = strval($val);
                        break;
                    case SPH_ATTR_FACTORS:
                        $attrvals[$attrName] = strval($val);
                        break;
                    case SPH_ATTR_MULTI:
                    case SPH_ATTR_MULTI64:
                        if (is_string($val) && strlen($val) > 0) {
                            $attrvals[$attrName] = array_map('intval', explode(',', $val));
                        } elseif (is_array($val)) {
                            $attrvals[$attrName] = $val;
                        } else {
                            $attrvals[$attrName] = [];
                        }
                        break;
                    default:
                        $attrvals[$attrName] = sphFixUint(intval($val));
                        break;
                }
            }

            foreach ($row as $key => $val) {
                if ($key === 'id' || $key === $weightKey) {
                    continue;
                }

                if (array_key_exists($key, $attrvals)) {
                    continue;
                }

                if (in_array($key, $schema['fields'])) {
                    continue;
                }

                $attrvals[$key] = is_numeric($val)
                    ? (strpos(strval($val), '.') !== false ? floatval($val) : intval($val))
                    : strval($val);
            }

            if ($this->_arrayresult) {
                $matches[$idx] = ["id" => $doc, "weight" => $weight, "attrs" => $attrvals];
            } else {
                $matches[$doc] = ["weight" => $weight, "attrs" => $attrvals];
            }
            $idx++;
        }

        return $matches;
    }

    private function _FetchMeta($conn)
    {
        $meta = [];
        try {
            $stmt = $conn->query("SHOW META");
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $key        = isset($row['Variable_name']) ? $row['Variable_name'] : array_values($row)[0];
                    $val        = isset($row['Value']) ? $row['Value'] : (count($row) > 1 ? array_values($row)[1] : "");
                    $meta[$key] = $val;
                }
            }
        } catch (PDOException $e) {
            // SHOW META not available
        }
        return $meta;
    }

    private function _ExtractWordStats($meta)
    {
        $words = [];
        $i     = 0;
        while (isset($meta["keyword[$i]"])) {
            $word         = $meta["keyword[$i]"];
            $docs         = isset($meta["docs[$i]"]) ? $meta["docs[$i]"] : "0";
            $hits         = isset($meta["hits[$i]"]) ? $meta["hits[$i]"] : "0";
            $words[$word] = [
                "docs" => sprintf("%u", intval($docs)),
                "hits" => sprintf("%u", intval($hits)),
            ];
            $i++;
        }
        return $words;
    }

    private function _ExecuteSingleQuery($conn, $req)
    {
        $result = [
            "error"       => "",
            "warning"     => "",
            "status"      => SEARCHD_OK,
            "fields"      => [],
            "attrs"       => [],
            "matches"     => [],
            "total"       => "0",
            "total_found" => "0",
            "time"        => "0.000",
            "words"       => [],
        ];

        if ($this->_token_filter_library !== '') {
            $result["warning"] = "SetTokenFilter is not supported in SphinxQL mode and will be ignored";
        }

        try {
            $stmt = $conn->query($req['sql']);
            if ($stmt === false) {
                $result["error"]  = "SphinxQL query failed";
                $result["status"] = SEARCHD_ERROR;
                return $result;
            }

            $schema           = $this->_GetIndexSchema($conn, $req['index']);
            $result["fields"] = $schema['fields'];
            $result["attrs"]  = $schema['attrs'];

            $rows              = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result["matches"] = $this->_TransformRows($rows, $schema);

            $meta = $this->_FetchMeta($conn);
            if (isset($meta['total'])) {
                $result["total"] = sprintf("%u", intval($meta['total']));
            }
            if (isset($meta['total_found'])) {
                $result["total_found"] = sprintf("%u", intval($meta['total_found']));
            }
            if (isset($meta['time'])) {
                $result["time"] = sprintf("%.3f", floatval($meta['time']));
            }
            $result["words"] = $this->_ExtractWordStats($meta);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if ($this->_IsConnectionError($e)) {
                $this->_connerror = true;
                $this->_conn      = null;
            }
            $result["error"]  = "SphinxQL error: " . $msg;
            $result["status"] = SEARCHD_ERROR;
        }

        return $result;
    }

    public function SetLimits($offset, $limit, $max = 0, $cutoff = 0)
    {
        assert(is_int($offset));
        assert(is_int($limit));
        assert($offset >= 0);
        assert($limit > 0);
        assert($max >= 0);
        $this->_offset = $offset;
        $this->_limit  = $limit;
        if ($max > 0) {
            $this->_maxmatches = $max;
        }
        if ($cutoff > 0) {
            $this->_cutoff = $cutoff;
        }
    }

    public function SetMaxQueryTime($max)
    {
        assert(is_int($max));
        assert($max >= 0);
        $this->_maxquerytime = $max;
    }

    public function SetRankingMode($ranker, $rankexpr = "")
    {
        assert($ranker === 0 || $ranker >= 1 && $ranker < SPH_RANK_TOTAL);
        assert(is_string($rankexpr));
        $this->_ranker   = $ranker;
        $this->_rankexpr = $rankexpr;
    }

    public function SetSortMode($mode, $sortby = "")
    {
        assert(
            $mode == SPH_SORT_RELEVANCE ||
            $mode == SPH_SORT_ATTR_DESC ||
            $mode == SPH_SORT_ATTR_ASC ||
            $mode == SPH_SORT_TIME_SEGMENTS ||
            $mode == SPH_SORT_EXTENDED
        );
        assert(is_string($sortby));
        assert($mode == SPH_SORT_RELEVANCE || strlen($sortby) > 0);

        $this->_sort   = $mode;
        $this->_sortby = $sortby;
    }

    public function SetWeights($weights)
    {
        die("This method is now deprecated; please use SetFieldWeights instead");
    }

    public function SetFieldWeights($weights)
    {
        assert(is_array($weights));
        foreach ($weights as $name => $weight) {
            assert(is_string($name));
            assert(is_int($weight));
        }
        $this->_fieldweights = $weights;
    }

    public function SetIndexWeights($weights)
    {
        assert(is_array($weights));
        foreach ($weights as $index => $weight) {
            assert(is_string($index));
            assert(is_int($weight));
        }
        $this->_indexweights = $weights;
    }

    public function SetIDRange($min, $max)
    {
        assert(is_numeric($min));
        assert(is_numeric($max));
        assert($min <= $max);
        $this->_min_id = $min;
        $this->_max_id = $max;
    }

    public function SetFilter($attribute, $values, $exclude = false)
    {
        assert(is_string($attribute));
        assert(is_array($values));

        if (count($values)) {
            foreach ($values as $value) {
                assert(is_numeric($value));
            }

            $this->_filters[] = [
                "type"    => SPH_FILTER_VALUES,
                "attr"    => $attribute,
                "exclude" => $exclude,
                "values"  => $values,
            ];
        }
    }

    public function SetFilterString($attribute, $value, $exclude = false)
    {
        assert(is_string($attribute));
        assert(is_string($value));
        $this->_filters[] = [
            "type"    => SPH_FILTER_STRING,
            "attr"    => $attribute,
            "exclude" => $exclude,
            "value"   => $value,
        ];
    }

    public function SetFilterStringList($attribute, $value, $exclude = false)
    {
        assert(is_string($attribute));
        assert(is_array($value));

        foreach ($value as $v) {
            assert(is_string($v));
        }

        $this->_filters[] = [
            "type"    => SPH_FILTER_STRING_LIST,
            "attr"    => $attribute,
            "exclude" => $exclude,
            "values"  => $value,
        ];
    }

    public function SetFilterRange($attribute, $min, $max, $exclude = false)
    {
        assert(is_string($attribute));
        assert(is_numeric($min));
        assert(is_numeric($max));
        assert($min <= $max);

        $this->_filters[] = [
            "type"    => SPH_FILTER_RANGE,
            "attr"    => $attribute,
            "exclude" => $exclude,
            "min"     => $min,
            "max"     => $max,
        ];
    }

    public function SetFilterFloatRange($attribute, $min, $max, $exclude = false)
    {
        assert(is_string($attribute));
        assert(is_float($min));
        assert(is_float($max));
        assert($min <= $max);

        $this->_filters[] = [
            "type"    => SPH_FILTER_FLOATRANGE,
            "attr"    => $attribute,
            "exclude" => $exclude,
            "min"     => $min,
            "max"     => $max,
        ];
    }

    public function SetGroupBy($attribute, $func, $groupsort = "@group desc")
    {
        assert(is_string($attribute));
        assert(is_string($groupsort));
        assert(
            $func == SPH_GROUPBY_DAY ||
            $func == SPH_GROUPBY_WEEK ||
            $func == SPH_GROUPBY_MONTH ||
            $func == SPH_GROUPBY_YEAR ||
            $func == SPH_GROUPBY_ATTR ||
            $func == SPH_GROUPBY_ATTRPAIR
        );

        $this->_groupby   = $attribute;
        $this->_groupfunc = $func;
        $this->_groupsort = $groupsort;
    }

    public function SetGroupDistinct($attribute)
    {
        assert(is_string($attribute));
        $this->_groupdistinct = $attribute;
    }

    public function SetRetries($count, $delay = 0)
    {
        assert(is_int($count) && $count >= 0);
        assert(is_int($delay) && $delay >= 0);
        $this->_retrycount = $count;
        $this->_retrydelay = $delay;
    }

    public function SetArrayResult($arrayresult)
    {
        assert(is_bool($arrayresult));
        $this->_arrayresult = $arrayresult;
    }

    public function SetSelect($select)
    {
        assert(is_string($select));
        $this->_select = $select;
    }

    public function SetQueryFlag($flag_name, $flag_value)
    {
        $known_names = ["reverse_scan", "sort_method", "max_predicted_time", "boolean_simplify", "idf", "global_idf", "low_priority", "lax_agent_errors"];
        $flags       = [
            "reverse_scan"       => [0, 1],
            "sort_method"        => ["pq", "kbuffer"],
            "max_predicted_time" => [0],
            "boolean_simplify"   => [true, false],
            "idf"                => ["normalized", "plain", "tfidf_normalized", "tfidf_unnormalized"],
            "global_idf"         => [true, false],
            "low_priority"       => [true, false],
            "lax_agent_errors"   => [true, false],
        ];

        assert(isset($flag_name, $known_names));
        assert(in_array($flag_value, $flags[$flag_name], true) || ($flag_name == "max_predicted_time" && is_int($flag_value) && $flag_value >= 0));

        if ($flag_name == "reverse_scan") {
            $this->_query_flags = sphSetBit($this->_query_flags, 0, $flag_value == 1);
        }
        if ($flag_name == "sort_method") {
            $this->_query_flags = sphSetBit($this->_query_flags, 1, $flag_value == "kbuffer");
        }
        if ($flag_name == "max_predicted_time") {
            $this->_query_flags   = sphSetBit($this->_query_flags, 2, $flag_value > 0);
            $this->_predictedtime = (int) $flag_value;
        }
        if ($flag_name == "boolean_simplify") {
            $this->_query_flags = sphSetBit($this->_query_flags, 3, $flag_value);
        }
        if ($flag_name == "idf" && ($flag_value == "normalized" || $flag_value == "plain")) {
            $this->_query_flags = sphSetBit($this->_query_flags, 4, $flag_value == "plain");
        }
        if ($flag_name == "global_idf") {
            $this->_query_flags = sphSetBit($this->_query_flags, 5, $flag_value);
        }
        if ($flag_name == "idf" && ($flag_value == "tfidf_normalized" || $flag_value == "tfidf_unnormalized")) {
            $this->_query_flags = sphSetBit($this->_query_flags, 6, $flag_value == "tfidf_normalized");
        }
        if ($flag_name == "low_priority") {
            $this->_query_flags = sphSetBit($this->_query_flags, 8, $flag_value);
        }
        if ($flag_name == "lax_agent_errors") {
            $this->_query_flags = sphSetBit($this->_query_flags, 10, $flag_value);
        }
    }

    public function SetOuterSelect($orderby, $offset, $limit)
    {
        assert(is_string($orderby));
        assert(is_int($offset));
        assert(is_int($limit));
        assert($offset >= 0);
        assert($limit > 0);

        $this->_outerorderby = $orderby;
        $this->_outeroffset  = $offset;
        $this->_outerlimit   = $limit;
        $this->_hasouter     = true;
    }

    public function SetTokenFilter($library, $name, $opts = "")
    {
        assert(is_string($library));
        assert(is_string($name));
        assert(is_string($opts));

        $this->_token_filter_library = $library;
        $this->_token_filter_name    = $name;
        $this->_token_filter_opts    = $opts;
    }

    public function ResetFilters()
    {
        $this->_filters = [];
    }

    public function ResetGroupBy()
    {
        $this->_groupby       = "";
        $this->_groupfunc     = SPH_GROUPBY_DAY;
        $this->_groupsort     = "@group desc";
        $this->_groupdistinct = "";
    }

    public function ResetQueryFlag()
    {
        $this->_query_flags   = sphSetBit(0, 6, true);
        $this->_predictedtime = 0;
    }

    public function ResetOuterSelect()
    {
        $this->_outerorderby = '';
        $this->_outeroffset  = 0;
        $this->_outerlimit   = 0;
        $this->_hasouter     = false;
    }

    public function Query($query, $index = "*", $comment = "")
    {
        assert(empty($this->_reqs));

        $this->AddQuery($query, $index, $comment);
        $results     = $this->RunQueries();
        $this->_reqs = [];

        if (! is_array($results)) {
            return false;
        }

        $this->_error   = $results[0]["error"];
        $this->_warning = $results[0]["warning"];
        if ($results[0]["status"] == SEARCHD_ERROR) {
            return false;
        } else {
            return $results[0];
        }
    }

    public function AddQuery($query, $index = "*", $comment = "")
    {
        $this->_MBPush();

        if ($this->_token_filter_library !== '') {
            $this->_warning = "SetTokenFilter is not supported in SphinxQL mode and will be ignored";
        }

        $sql = $this->_BuildSphinxQL($query, $index, $comment);

        $this->_reqs[] = [
            'sql'     => $sql,
            'index'   => $index,
            'comment' => $comment,
        ];

        $this->_MBPop();

        return count($this->_reqs) - 1;
    }

    public function RunQueries()
    {
        if (empty($this->_reqs)) {
            $this->_error = "no queries defined, issue AddQuery() first";
            return false;
        }

        $this->_MBPush();

        if (! ($conn = $this->_Connect())) {
            $this->_MBPop();
            return false;
        }

        $results = [];
        foreach ($this->_reqs as $req) {
            $results[] = $this->_ExecuteSingleQuery($conn, $req);
        }

        $this->_reqs = [];
        $this->_DisconnectAfterQuery();

        $this->_MBPop();
        return $results;
    }

    public function BuildExcerpts($docs, $index, $words, $opts = [])
    {
        assert(is_array($docs));
        assert(is_string($index));
        assert(is_string($words));
        assert(is_array($opts));

        $this->_MBPush();

        if (! ($conn = $this->_Connect())) {
            $this->_MBPop();
            return false;
        }

        if (! isset($opts["before_match"])) {
            $opts["before_match"] = "<b>";
        }

        if (! isset($opts["after_match"])) {
            $opts["after_match"] = "</b>";
        }

        if (! isset($opts["chunk_separator"])) {
            $opts["chunk_separator"] = " ... ";
        }

        if (! isset($opts["field_separator"])) {
            $opts["field_separator"] = "<br>";
        }

        if (! isset($opts["limit"])) {
            $opts["limit"] = 256;
        }

        if (! isset($opts["limit_passages"])) {
            $opts["limit_passages"] = 0;
        }

        if (! isset($opts["limit_words"])) {
            $opts["limit_words"] = 0;
        }

        if (! isset($opts["around"])) {
            $opts["around"] = 5;
        }

        if (! isset($opts["exact_phrase"])) {
            $opts["exact_phrase"] = false;
        }

        if (! isset($opts["single_passage"])) {
            $opts["single_passage"] = false;
        }

        if (! isset($opts["use_boundaries"])) {
            $opts["use_boundaries"] = false;
        }

        if (! isset($opts["weight_order"])) {
            $opts["weight_order"] = false;
        }

        if (! isset($opts["query_mode"])) {
            $opts["query_mode"] = false;
        }

        if (! isset($opts["force_all_words"])) {
            $opts["force_all_words"] = false;
        }

        if (! isset($opts["start_passage_id"])) {
            $opts["start_passage_id"] = 1;
        }

        if (! isset($opts["load_files"])) {
            $opts["load_files"] = false;
        }

        if (! isset($opts["html_strip_mode"])) {
            $opts["html_strip_mode"] = "index";
        }

        if (! isset($opts["allow_empty"])) {
            $opts["allow_empty"] = false;
        }

        if (! isset($opts["passage_boundary"])) {
            $opts["passage_boundary"] = "none";
        }

        if (! isset($opts["emit_zones"])) {
            $opts["emit_zones"] = false;
        }

        if (! isset($opts["load_files_scattered"])) {
            $opts["load_files_scattered"] = false;
        }

        $res = [];
        try {
            foreach ($docs as $doc) {
                assert(is_string($doc));

                $params   = [];
                $params[] = $this->_pdoQuote($doc);
                $params[] = $this->_quoteAttr($index);
                $params[] = $this->_pdoQuote($words);

                $str_opts = [
                    "before_match", "after_match", "chunk_separator", "field_separator",
                    "html_strip_mode", "passage_boundary",
                ];
                foreach ($str_opts as $name) {
                    if (isset($opts[$name])) {
                        $params[] = $this->_pdoQuote(strval($opts[$name])) . " AS " . $name;
                    }
                }

                $int_opts = [
                    "limit", "around", "limit_passages", "limit_words", "start_passage_id",
                ];
                foreach ($int_opts as $name) {
                    if (isset($opts[$name])) {
                        $params[] = intval($opts[$name]) . " AS " . $name;
                    }
                }

                $bool_opts = [
                    "exact_phrase", "single_passage", "use_boundaries", "weight_order",
                    "query_mode", "force_all_words", "load_files", "allow_empty",
                    "emit_zones", "load_files_scattered",
                ];
                foreach ($bool_opts as $name) {
                    if (isset($opts[$name]) && $opts[$name]) {
                        $params[] = "1 AS " . $name;
                    }
                }

                $sql  = "CALL SNIPPETS(" . implode(", ", $params) . ")";
                $stmt = $conn->query($sql);
                if ($stmt) {
                    $row   = $stmt->fetch(PDO::FETCH_NUM);
                    $res[] = $row ? strval($row[0]) : "";
                } else {
                    $res[] = "";
                }
            }
        } catch (PDOException $e) {
            $this->_error = "SphinxQL error in CALL SNIPPETS: " . $e->getMessage();
            if ($this->_IsConnectionError($e)) {
                $this->_connerror = true;
                $this->_conn      = null;
            }
            $this->_DisconnectAfterQuery();
            $this->_MBPop();
            return false;
        }

        $this->_DisconnectAfterQuery();
        $this->_MBPop();
        return $res;
    }

    public function BuildKeywords($query, $index, $hits)
    {
        assert(is_string($query));
        assert(is_string($index));
        assert(is_bool($hits));

        $this->_MBPush();

        if (! ($conn = $this->_Connect())) {
            $this->_MBPop();
            return false;
        }

        $sql = "CALL KEYWORDS(" . $this->_pdoQuote($query) . ", " . $this->_quoteAttr($index);
        if ($hits) {
            $sql .= ", 1";
        }
        $sql .= ")";

        try {
            $stmt = $conn->query($sql);
            if (! $stmt) {
                $this->_error = "CALL KEYWORDS failed";
                $this->_DisconnectAfterQuery();
                $this->_MBPop();
                return false;
            }

            $res = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $entry = [
                    "tokenized"  => isset($row['tokenized']) ? $row['tokenized'] : "",
                    "normalized" => isset($row['normalized']) ? $row['normalized'] : "",
                ];
                if ($hits) {
                    $entry["docs"] = isset($row['docs']) ? intval($row['docs']) : 0;
                    $entry["hits"] = isset($row['hits']) ? intval($row['hits']) : 0;
                }
                $res[] = $entry;
            }
        } catch (PDOException $e) {
            $this->_error = "SphinxQL error in CALL KEYWORDS: " . $e->getMessage();
            if ($this->_IsConnectionError($e)) {
                $this->_connerror = true;
                $this->_conn      = null;
            }
            $this->_DisconnectAfterQuery();
            $this->_MBPop();
            return false;
        }

        $this->_DisconnectAfterQuery();
        $this->_MBPop();
        return $res;
    }

    public function EscapeString($string)
    {
        $from = ['\\', '(', ')', '|', '-', '!', '@', '~', '"', '&', '/', '^', '$', '=', '<'];
        $to   = ['\\\\', '\(', '\)', '\|', '\-', '\!', '\@', '\~', '\"', '\&', '\/', '\^', '\$', '\=', '\<'];

        return str_replace($from, $to, $string);
    }

    public function UpdateAttributes($index, $attrs, $values, $type = SPH_UPDATE_PLAIN, $ignorenonexistent = false)
    {
        assert(is_string($index));
        assert(is_bool($ignorenonexistent));
        assert($type == SPH_UPDATE_PLAIN || $type == SPH_UPDATE_MVA || $type == SPH_UPDATE_STRING || $type == SPH_UPDATE_JSON);

        $mva    = $type == SPH_UPDATE_MVA;
        $string = $type == SPH_UPDATE_STRING || $type == SPH_UPDATE_JSON;

        assert(is_array($attrs));
        foreach ($attrs as $attr) {
            assert(is_string($attr));
        }

        assert(is_array($values));
        foreach ($values as $id => $entry) {
            assert(is_numeric($id));
            assert(is_array($entry));
            assert(count($entry) == count($attrs));
            foreach ($entry as $v) {
                if ($mva) {
                    assert(is_array($v));
                    foreach ($v as $vv) {
                        assert(is_int($vv));
                    }
                } elseif ($string) {
                    assert(is_string($v));
                } else {
                    assert(is_int($v));
                }
            }
        }

        $this->_MBPush();

        if (! ($conn = $this->_Connect())) {
            $this->_MBPop();
            return -1;
        }

        $updated = 0;
        try {
            foreach ($values as $id => $entry) {
                $set_parts = [];
                for ($i = 0; $i < count($attrs); $i++) {
                    $attr = $this->_quoteAttr($attrs[$i]);
                    $val  = $entry[$i];

                    if ($mva) {
                        $mva_vals    = implode(",", array_map('intval', $val));
                        $set_parts[] = $attr . "=(" . $mva_vals . ")";
                    } elseif ($string) {
                        $set_parts[] = $attr . "=" . $this->_pdoQuote($val);
                    } else {
                        $set_parts[] = $attr . "=" . intval($val);
                    }
                }

                $opt_clause = $ignorenonexistent ? " OPTION ignore_nonexistent_columns=1" : "";
                $sql        = "UPDATE " . $this->_quoteAttr($index)
                . " SET " . implode(", ", $set_parts)
                . " WHERE id=" . intval($id)
                    . $opt_clause;

                $conn->exec($sql);
                $updated += $conn->rowCount();
            }
        } catch (PDOException $e) {
            $this->_error = "SphinxQL error in UPDATE: " . $e->getMessage();
            if ($this->_IsConnectionError($e)) {
                $this->_connerror = true;
                $this->_conn      = null;
            }
            $this->_DisconnectAfterQuery();
            $this->_MBPop();
            return -1;
        }

        $this->_DisconnectAfterQuery();
        $this->_MBPop();
        return $updated;
    }

    public function Open()
    {
        if ($this->_persistent) {
            $this->_error = 'already connected';
            return false;
        }
        if (! $conn = $this->_Connect()) {
            return false;
        }

        $this->_persistent = true;
        return true;
    }

    public function Close()
    {
        if (! $this->_persistent && $this->_conn === null) {
            $this->_error = 'not connected';
            return false;
        }

        $this->_conn       = null;
        $this->_persistent = false;
        return true;
    }

    public function Status($session = false)
    {
        assert(is_bool($session));

        $this->_MBPush();
        if (! ($conn = $this->_Connect())) {
            $this->_MBPop();
            return false;
        }

        try {
            $stmt = $conn->query("SHOW STATUS");
            if (! $stmt) {
                $this->_error = "SHOW STATUS failed";
                $this->_DisconnectAfterQuery();
                $this->_MBPop();
                return false;
            }

            $res = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $name  = isset($row['Variable_name']) ? $row['Variable_name'] : array_values($row)[0];
                $val   = isset($row['Value']) ? $row['Value'] : (count($row) > 1 ? array_values($row)[1] : "");
                $res[] = [$name, $val];
            }
        } catch (PDOException $e) {
            $this->_error = "SphinxQL error in SHOW STATUS: " . $e->getMessage();
            if ($this->_IsConnectionError($e)) {
                $this->_connerror = true;
                $this->_conn      = null;
            }
            $this->_DisconnectAfterQuery();
            $this->_MBPop();
            return false;
        }

        $this->_DisconnectAfterQuery();
        $this->_MBPop();
        return $res;
    }

    public function FlushAttributes()
    {
        $this->_MBPush();
        if (! ($conn = $this->_Connect())) {
            $this->_MBPop();
            return -1;
        }

        try {
            $conn->exec("FLUSH ATTRIBUTES");
            $this->_DisconnectAfterQuery();
            $this->_MBPop();
            return 0;
        } catch (PDOException $e) {
            $this->_error = "SphinxQL error in FLUSH ATTRIBUTES: " . $e->getMessage();
            if ($this->_IsConnectionError($e)) {
                $this->_connerror = true;
                $this->_conn      = null;
            }
            $this->_DisconnectAfterQuery();
            $this->_MBPop();
            return -1;
        }
    }

    public function Ping()
    {
        try {
            if (! $conn = $this->_Connect()) {
                return false;
            }

            $stmt = $conn->query("SELECT 1");
            return ($stmt !== false);
        } catch (PDOException $e) {
            return false;
        }
    }
}
