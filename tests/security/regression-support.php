<?php
function load_function($path, $name) {
    $tokens = token_get_all(file_get_contents($path));
    for ($i=0;$i<count($tokens);$i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
        $j=$i+1;while (is_array($tokens[$j]) && $tokens[$j][0]===T_WHITESPACE) $j++;
        if (!is_array($tokens[$j]) || $tokens[$j][1] !== $name) continue;
        $code='';$depth=0;$started=false;
        for (;$i<count($tokens);$i++) {
            $t=$tokens[$i];$code.=is_array($t)?$t[1]:$t;
            if ($t==='{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {$depth++;$started=true;}
            if ($t==='}') $depth--;
            if ($started && $depth===0) {eval(str_replace('__DIR__', var_export(dirname($path), true), $code));return;}
        }
    }
    throw new RuntimeException('Missing function '.$name);
}
function check($value,$message) {if (!$value) throw new RuntimeException($message);}

