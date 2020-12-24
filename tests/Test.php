<?php
namespace GdShenrun\Caiss\File\Tests;

use GdShenrun\Caiss\File\Etag;

class Test
{
    public static function testSum(){
        $result = Etag::sum(__DIR__.'/iPhone.png');
        assert($result[1] !== null, $result[1]);
        assert('FmJsErEsANrTmobPDYjod-9UkKON' === $result[0]);
    }

    public static function testFile(){

    }
}
