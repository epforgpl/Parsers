<?php

require(dirname(__FILE__) . "/../simple_html_dom.php");
require(dirname(__FILE__) . "/../ParserUtils.php");

class RadniParser
{

    /**
     * Lista wszystkich radnych
     * @param $url
     */
    function parseRadni($url = "https://www.bip.krakow.pl/?bip_id=1&mmi=11952")
    {
        $html = file_get_html($url);

        $title = $html->find('#mainDiv > h3', 0)->plaintext;

        if (preg_match('/ ([IXV]+) kadencji$/', $title, $matches)) {
            $kadencja_rzymskie = $matches[1];
        }

        $radni = array();
        foreach ($html->find('#mainDiv > table td a') as $radny_link) {
            $radni[] = array(
                'imie_nazwisko' => $radny_link->title,
                'link' => ParserUtils::absolute_link($url, $radny_link->href)
            );
        }

        return compact('title', 'kadencja_rzymskie', 'url', 'radni');
    }

    /**
     * Url przykładowy: https://www.bip.krakow.pl/?sub_dok_id=29706
     */
    function parseRadny($url)
    {
        $html = file_get_html($url);

        $imie_nazwisko = $html->find('#mainDiv > h2', 0)->plaintext;
        $klub_radnych = $html->find('#mainDiv > p', 0)->plaintext;

        $description = array();
        $idx = 0;
        foreach ($html->find('#mainDiv > p') as $p) {
            $txt = $this->text($p);

            $idx++;
            if ($idx == 1) {
                continue;

            } else if (preg_match('/@/', $txt)) {
                $email = $txt;
                break;

            } else if (!empty($txt)) {
                $description[] = $p->plaintext;
            }
        }

        $dyzury_link = ParserUtils::absolute_link($url, $html->find('#mainDiv > p a[title^="Dyżury"]', 0)->href);
        $oswiadczenia_link = ParserUtils::absolute_link($url, $html->find('#mainDiv > p a[title^="Oświadczenia"]', 0)->href);

        return compact('imie_nazwisko', 'klub_radnych', 'email', 'description', 'dyzury_link', 'oswiadczenia_link', 'url');
    }

    /**
     * Przetwarza dyzury w danym roku
     * @param $url np. https://www.bip.krakow.pl/?sub_dok_id=59321
     */
    function parseRadnyDyzuryRok($url)
    {
        $html = file_get_html($url);

        $dyzury = array();
        $inne = array();
        foreach ($html->find('#mainDiv > p') as $dyzur) {
            $tytul = $this->text($dyzur->find('strong', 0));
            if ($tytul == null) {
                continue;
            }

            if (preg_match('/^(\d+)\s+(\w+)\s+(\d+)/', $tytul, $matches)) {
                // dyzur
                $opis = $dyzur->find('text');

                $dyzury[] = array(
                    'day' => parseInt($matches[1]),
                    'month' => ParserUtils::map_pl_month($matches[2]),
                    'year' => parseInt($matches[3]),
                    'hours' => $this->text($opis[1]),
                    'place' => $this->text($opis[2]),
                    'address' => $this->text($opis[3]),
                );

            } else {
                // inne
                $inne[] = $dyzur->plaintext;
            }
        }

        return compact('url', 'dyzury');
    }

    /**
     * Przetwarza liste wszystkich oswiadczen majatkowych
     * @param $url np. https://www.bip.krakow.pl/?bip_id=1&mmi=40&nazwisko=Bassara&imie=Magdalena&grupa=radny&filled=yes&numerStr=1
     */
    function parseRadnyOswiadczenia($url)
    {
        $html = file_get_html($url);

        $oswiadczenia = array();

        $first = true;
        foreach ($html->find('#mainDiv > table tr') as $row) {
            if ($first) {
                $first = false;
                continue;
            }

            $oswiadczenia[] = array(
                'imie_nazwisko' => $this->text($row->children(1)),
                'jednostka' => $this->text($row->children(2)),
                'rok' => $this->text($row->children(3)),
                'link' => ParserUtils::absolute_link($url, $row->find('td:nth-child(4) a', 0)->href)
            );
        }

        return compact('url', 'oswiadczenia');
    }

    /**
     * Przetwarza wszystkie interpelacje w zadanym okresie
     *
     * @param $date_limit Limit daty (włącznie z nią), string Y-m-d lub DateTime
     * @param $url
     */
    function parseInterpelacjeAll($date_limit = null, $url = 'http://www.bip.krakow.pl/?sub_dok_id=30308&sub=interpelacje&co=shwInterp.php&ktory=0&ileWierszy=100')
    {
        $html = file_get_html($url);
        $oswiadczenia = array();

        if (is_string($date_limit)) {
            $date_limit = ParserUtils::parseDateYmd($date_limit);
        }

        foreach($html->find('#mainDiv > table', 0)->find('tr') as $row) {
            if (!$row->find('td')) {
                continue;
            }

            $tresc_link = ParserUtils::absolute_link($url, $row->children(4)->find('a', 0)->href);
            $tresc_link_arr = parse_url($tresc_link);

            $data_zgloszenia_text = $this->text($row->children(3));
            $data_zgloszenia  = ParserUtils::parseDateYmd($data_zgloszenia_text);

            if ($date_limit != null && $data_zgloszenia < $date_limit) {
                return $oswiadczenia;
            }

            $oswiadczenia[] = array(
                'sesja' => $this->text($row->children(0)),
                'imie_nazwisko' => preg_replace('/\s+/', ' ',$this->text($row->children(1))),
                'temat_interpelacji' => $this->text($row->children(2)),
                'data_zgloszenia' => $data_zgloszenia,
                'tresc_link' => $tresc_link,
                'tresc_unique_key' => array_pop(explode("/", $tresc_link_arr['path'])),
                'odpowiedz_link' => ParserUtils::absolute_link($url, $row->children(5)->find('a', 0)->href)
            );
        }

        // przetworzono, a nie dotarto do limitu daty
        if (count($html->find('#mainDiv > table', 0)->find('tr')) <= 1) {
            // brak danych
            return array();
        }

        // przetwarzamy kolejna strone
        $url_parts = parse_url($url);
        parse_str($url_parts['query'], $query_params);

        $query_params['ktory'] += $query_params['ileWierszy'];

        $url_parts['query'] = http_build_query($query_params);
        $next_page_url = ParserUtils::createUrl($url_parts);

        $following_pages = $this->parseInterpelacjeAll($date_limit, $next_page_url);

        return array_merge($oswiadczenia, $following_pages);
    }

    function text($el)
    {
        return trim(ParserUtils::text($el), " \t\n\r\0\x0B" . chr(194) . chr(160));
    }
}