<?php

namespace FpDbTest;

use Exception;
use mysqli;

class TemplateParser 
{
    private mysqli $mysqli;
    private array $args; 
    private string $regexp_string;
    private $skip_value;
    private bool $skip_this;
    
    private int $counter_replace = 0;
    private int $recursion_lvl=0;
    private int $allow_recursion_lvl;  
    

    public function __construct(mysqli $mysqli, array $args, $skip_value,int $allow_recursion_lvl=1) {
        $this->mysqli = $mysqli;
        $this->args = $args;
        $this->regexp_string = '~\?d|\?f|\?a|\?#|\?|{(?P<conditional_block>.*)}~';
        $this->skip_this=false;        
        $this->skip_value = $skip_value;
        $this->allow_recursion_lvl = $allow_recursion_lvl>1 ? $allow_recursion_lvl: 1;
    }

    public function getConterReplace() : int {
        return $this->counter_replace;
    }

    public function parse(string $query,$check_skip=false){
        $result_query = preg_replace_callback(
            $this->regexp_string,
            function($match) use ($check_skip){
                // парсинг условного блока
                if (!empty($match['conditional_block'])) {
                    $this->recursion_lvl++;
                    if ($this->recursion_lvl>$this->allow_recursion_lvl) {
                        throw new Exception("The task condition limits the nesting level of the conditional block. But if you really want to => allow_recursion_lvl", 1);
                    }
                    $result_arg = $this->parse($match['conditional_block'],true);
                    $this->skip_this = false;
                } else {
                    // проверка что параметры еще есть
                    if (empty($this->args)) {
                        throw new Exception("Parser error: not all parameter values are specified",1);
                    }
                    // получить параметр
                    $current_arg = array_shift($this->args);
                    // проверить параметр со спец значением
                    if ($check_skip) {
                        if ($current_arg === $this->skip_value) {
                            $this->skip_this = true;
                            return '';
                        }
                    }
                    $this->counter_replace++;
                    switch ($match[0]) {
                        case '?d':
                            $result_arg = $this->convInt($current_arg);
                            break;
                        case '?f':
                            $result_arg = $this->convFloat($current_arg);
                            break;
                        case '?a':
                            $result_arg = $this->convArray($current_arg);
                            break;
                        case '?#':
                            $result_arg = $this->convIdentif($current_arg);
                            break;
                        case '?':
                            $result_arg = $this->convUniversal($current_arg);
                            break;
                        default:
                            throw new Exception("Parser error: check the regular expression", 1);
                            break;
                    }
                }
                return $result_arg;
            },$query,-1,$count
        );
        // удаление условного блока с универсальным параметром
        if ($this->skip_this) {
            $this->counter_replace++;
            return '';
        }
        return $result_query;
    }

    // Конвертация целого числа
    private function convInt($arg){
        if (is_null($arg)) {
            return 'NULL';
        }
        if (is_scalar($arg)) {
            return intval($arg,0);
        }
        throw new Exception("Parser error: Not a suitable parameter", 1);
    }
    // Конвертация числа с плавающей точкой
    private function convFloat($arg){
        if (is_null($arg)) {
            return 'NULL';
        }
        if (is_scalar($arg)) {
            return floatval($arg);
        }
        throw new Exception("Parser error: Not a suitable parameter", 1);
    }
    // Конвертация универсального параметра
    public function convUniversal($arg) {
        if (is_null($arg)) {
            return 'NULL';
        }
        if (is_int($arg) || is_bool($arg)) {
            return $this->convInt($arg);
        }
        if (is_float($arg)) {
            return $this->convFloat($arg);
        }
        if (is_string($arg)) {
            return "'".$this->mysqli->real_escape_string($arg)."'";
        }
        
    }
    // Конвертация идентификатора / массива идентификаторов
    public function convIdentif($arg) {
        if (is_array($arg)) {
            $result_arg = [];
            foreach ($arg as $value) {
                $result_arg[]='`'.$this->mysqli->real_escape_string($value).'`';
            }
            return join(", ",$result_arg);
        }
        if (is_string($arg)) {
            return '`'.$this->mysqli->real_escape_string($arg).'`';
        }
        throw new Exception("Parser error: Not a suitable parameter", 1);
    }
    // Конвертация массива
    public function convArray($arg) {
        if (!is_array($arg)) {
            throw new Exception("Parser error: Not a suitable parameter", 1);
        }
        $result_arg=[];
        $flag_list = array_is_list($arg);
        foreach ($arg as $key => $value) {
            if (is_array($value)) {
                throw new Exception("Parser error: the <?a> parameter does not allow Multidimensional array", 1);
            }
            if ($flag_list) {
                $result_arg[]= $this->convUniversal($value);
            } else {
                $result_arg[]='`'.$this->mysqli->real_escape_string($key).'` = '.$this->convUniversal($value);
            }
        }
        return join(", ",$result_arg);
    }
}
