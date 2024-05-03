<?php

namespace FpDbTest;

use Exception;
use mysqli;
use FpDbTest\TemplateParser;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function buildQuery(string $query, array $args = []): string
    {
        $parser = new TemplateParser($this->mysqli,$args,$this->skip());
        $result_query = $parser->parse($query);
        $use_count_args = $parser->getConterReplace();
        if (count($args) != $use_count_args) {
            throw new Exception("Parser error: a different number of parameters are specified", 1);
        }
        return $result_query;
    }

    public function skip()
    {
        return 1;
        //throw new Exception();
    }
}
