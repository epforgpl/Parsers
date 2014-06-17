<?

require_once('simple_html_dom.php');

function &array_find(&$arr, $func) {
    if (!is_array($arr)) {
	    throw new Exception("Not an array: $arr");
    }
    foreach($arr as $key => $value) {
        if ($func($value)) {
            return $arr[$key];
        }
    }
    $null_notice_hack = null;
    return $null_notice_hack;
}


class PkwParser
{
    private $baseUrl = "http://www.kadencja.pkw.gov.pl/obs/pl/";

    function __construct() {
        
    }
    
    function log($txt) {
        echo $txt . "\n";
    }

    private $type_instytucje = array(
        'sejmik województwa',
        'rada gminy',
        'rada powiatu',
        'rada miasta',
        'rada miejska',
        'rada m.st.',
        'rada dzielnicy',
        'prezydent',
        'wójt',
        'burmistrz'
    );

    private $type_subsection = array(
        'okręg wyborczy'
    );

    function pkw_map_type($type, $name)
    {
        foreach ($type as $i) {
            if (stripos($name, $i) !== false) {
                return $i;
            }
        }
        throw new Exception("Nieznany typ: " . $name);
    }

    function full_url($base_url, $path) {
        if (strpos($path, '::/') !== false) {
            return $path;

        } else if ($path[0] != '/') {
            $base_url = substr($base_url, 0, strrpos($base_url, '/') + 1);
            return $base_url . $path;

        } else {
            throw new Exception("path - url abs: " . $path);
        }
    }

    function text($el) {
        $txt = trim(str_replace('&nbsp;', '', $el->plaintext));
        return $txt == '-' ? null : $txt;
    }

    function parse_sections($html, $url) {
        $sections = array();
        foreach ($html->find('.obs_bkg') as $section) {
            $s = array();
            $s['name'] = $this->text($section->find('> h2.title', 0));
            $s['type'] = $this->pkw_map_type($this->type_instytucje, $s['name']);
            $s['subsections'] = array();

            foreach ($section->find('.consts') as $subsection) {
                $ss = array();
                $ss['name'] = $this->text($subsection->find('> h3.title',0));
                if ($ss['name'] != null) {
                    if (preg_match('/(\d+)$/', $ss['name'], $okreg_parsed))
                        $ss['okreg_num'] = $okreg_parsed[1];
                    $ss['type'] = $this->pkw_map_type($this->type_subsection, $ss['name']);
                }
                $ss['people'] = array();

                foreach ($subsection->find('.person') as $person) {
                    $p = array(
                        'name' => $this->text($person->find('span',0)),
                    );

                    // details
                    foreach ($person->find('table.histtab tr') as $info_row) {
                        $fld = trim($this->text($info_row->find('td', 0)), ":");
                        if ($fld == 'Komitet') {
                            $fld = 'komitet';
                        } else if($fld == 'Akcja wyborcza') {
                            $fld = 'wybranie_akcja_wyborcza';
                        } else if (strpos($fld, 'Data objęcia') == 0) {
                            $fld = 'wybranie_data';
                        } else {
                            throw new Exception("Unrecognized field: " . $fld);
                        }

                        $val = $info_row->find('td', 1);
                        $p[$fld] = $this->text($val);

                        if ($val->find('a', 0)) {
                            $p[$fld . '_link'] = $val->find('a', 0)->href;
                        }
                    }

                    array_push($ss['people'], $p);
                }

                array_push($s['subsections'], $ss);
            }

            array_push($sections, $s);
        }

        // dodanie zmian historycznych do aktualnego składu
        $historytab = $html->find('table.historytab', 0);
        $okreg_num = null;
        $okreg_str = null;
        foreach($historytab->find('tr') as $tr) {
            if (preg_match('/^Okręg wyborczy nr (\d+)$/', $this->text($tr), $okregs)) {
                // naglowek
                if ($tr->find('td', 0)->colspan != 8) {
                    throw new Exception("Zmienila sie struktura danych zmian. Inna liczba kolumn Liczba kolumn rozna od 8");
                }
                $okreg_num = $okregs[1];
                $okreg_str = $this->text($tr);

                $rada = &array_find($sections, function($s) {return strpos($s['type'], 'rada') === 0 || strpos($s['type'],'sejmik') === 0; });
                $okreg = &array_find($rada['subsections'], function($ss) use($okreg_str) {
                    return $ss['name'] == $okreg_str;
                });

                if ($okreg == null) {
                    throw new Exception("Prawdopodobnie zmienila sie struktura danych. Nie znaleziono okregu: " . $okreg_str . " w " . $url);
                }
            }

            $tds = $tr->find('td');
            if (count($tds) == 8) {
                // dane
                $nazwisko_imie = $this->text($tds[0]);

                $person_found = &array_find($okreg['people'], function($p) use($nazwisko_imie) { return $p['name'] == $nazwisko_imie;});
                if ($person_found) {
                    // więcej danych nt. ostatniego nie ma

                } else {
                    // dodajemy innych
                    $p = array(
                        'name' => $nazwisko_imie,
                        'komitet' => $this->text($tds[1]),
                        'wybranie_data' => $this->text($tds[2]),
                        'wybranie_akcja_wyborcza' => $this->text($tds[3]),
                        'wybranie_podstawa_prawna' => $this->text($tds[4]),
                        'rezygnacja_data' => $this->text($tds[5]),
                        'rezygnacja_akcja_wyborcza' => $this->text($tds[6]),
                        'rezygnacja_podstawa_prawna' => $this->text($tds[7])
                    );

                    if ($tds[3]->find('a', 0)) {
                        $p['wybranie_akcja_wyborcza_link'] = $tds[3]->find('a', 0)->href;
                    }

                    array_push($okreg['people'], $p);
                }
            }
        }

        if ($okreg_num == null) {
            throw new Exception("Zmienila sie struktura danych. Nie znaleziono zmian w okregach.");
        }

        return compact(array('sections'));
    }

    function parse_gmina($url)
    {
        $this->log("Parsing gmina: " . $url);

        if (!preg_match('/\/(\d+)\/(\d+)\.htm(l)?/', $url, $matches)) {
            throw new Exception("incorrect url");
        }

        $teryt_wojewodztwa = $matches[1];
        $teryt_gminy = $matches[2];

        $html = file_get_html($url);

        $name = $this->text($html->find('#map p.title', 0));

        $data = $this->parse_sections($html, $url);

        // TODO dodanie zmian z aktualnych wyborów uzupełniających (moze nie tylko do gmin)

        return array_merge($data, compact('teryt_wojewodztwa', 'teryt_gminy', 'name'));
    }

    function parse_gmina_teryt($teryt_gminy) {
        $teryt_woj = substr($teryt_gminy, 0, 2) . '0000';

        return $this->parse_gmina($this->baseUrl . $teryt_woj . '/' . $teryt_gminy . '.html');
    }

    function parse_powiat($url) {
        $this->log("Parsing powiat: " . $url);

        if (!preg_match('/\/(\d+)\/(\d+)\.htm(l)?/', $url, $matches)) {
            throw new Exception("incorrect url");
        }

        $teryt_wojewodztwa = $matches[1];
        $teryt_powiatu = $matches[2];

        $html = file_get_html($url);

        $name = $this->text($html->find('#map p.title', 0));

        $gminy = array();
        foreach($html->find('.obs_children h3 a') as $g) {
            array_push($gminy, $this->parse_gmina($this->full_url($url, $g->href)));
        }

        // rada powiatu
        $data = $this->parse_sections($html, $url);

        return array_merge($data, compact('teryt_wojewodztwa', 'teryt_powiatu', 'name', 'gminy'));
    }

    function parse_wojewodztwo($url) {
        $this->log("Parsing wojewodztwo: " . $url);

        if (!preg_match('/\/(\d+)\/(\d+)\.htm(l)?/', $url, $matches)) {
            throw new Exception("incorrect url");
        }

        $teryt_wojewodztwa = $matches[1];

        $html = file_get_html($url);

        $wojewodztwo_name = $this->text($html->find('#map p.title', 0));

        $powiaty = array();
        foreach($html->find('.obs_children h3 a') as $powiat) {
            array_push($powiaty, $this->parse_powiat($this->full_url($url,$powiat->href)));
        }

        // sejmik wojewódzki
        $data = $this->parse_sections($html, $url);

        return array_merge($data, compact('teryt_wojewodztwa', 'name', 'powiaty'));
    }

    function parse_kraj($url = 'http://www.kadencja.pkw.gov.pl/obs/pl/000000.html') {
        $this->log("Parsing pkw_data: " . $url);
        $html = file_get_html($url);

        $wojewodztwa = array();
        foreach($html->find('.obs_children h3 a') as $w) {
            array_push($wojewodztwa, $this->parse_wojewodztwo($this->full_url($url, $w->href)));
        }
    }
}

//$ret =   PkwParser::parse_wojewodztwo("http://www.kadencja.pkw.gov.pl/obs/pl/120000/120000.html");
//$ret = PkwParser::parse_gmina("http://www.kadencja.pkw.gov.pl/obs/pl/120000/126101.html");

//var_export($ret);

//PkwParser::parse_kraj();

