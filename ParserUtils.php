<?

class ParserUtils
{
    /**
     * Zbuduj absolutny link na podstawie atrybutu href
     *
     * @param $base_url URL przetwarzanej strony
     * @param $path Sciezka href linku
     * @return null|string
     */
    static function absolute_link($base_url, $path)
    {
        if ($path == null)
            return null;

        $path_parsed = parse_url($path);
        $base_url_parsed = parse_url($base_url);

        $url = array_merge($base_url_parsed, $path_parsed);
        return self::createUrl($url);
    }
    
    static function createUrl($parts) {
        return $parts['scheme']. '://' . $parts['host'].$parts['path'] . ( isset($parts['query']) ? '?' . $parts['query'] : ''); 
    }

    static function parseDateYmd($txt) {
        if ($txt == null)
            return null;

        $date =  DateTime::createFromFormat('Y-m-d', $txt);
        $date->setTime(0,0,0);

        if ($date === false) {
            throw new Exception("Nie udalo sie odczytac daty: $txt");
        }
        return $date;
    }

    static function text($el) {
        if ($el == null) {
            return null;
        }
        return trim(str_replace('&nbsp;', '', $el->plaintext));
    }

    static function map_pl_month($txt) {
        $txt = trim($txt);
        if ($txt == null) {
            return $txt;
        }

        $map = array(
            'stycznia' => 1,
            'lutego' => 2,
            'marca' => 3,
            'kwietnia' => 4,
            'maja' => 5,
            'czerwca' => 6,
            'lipca' => 7,
            'sierpnia' => 8,
            'września' => 9,
            'października' => 10,
            'listopada' => 11,
            'grudnia' => 12
        );

        if (isset($map[$txt]))
            return $map[$txt];

        else
            throw new Exception("Nierozpoznany miesiac: " . $txt);
    }
}