<?php

require_once("PkwParser.php");
require_once('../db.php');

error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);

function extract_date($datetime) {
    if ($datetime == null) {
        return null;

    } else {
        $data = explode(" ", $datetime);
        return $data[0];
    }
}

class PkwUpdater
{
    private $ctx, $DB;

    function __construct($ctx)
    {
        $this->ctx = $ctx;
        $this->DB = $ctx->DB;
    }

    public function quote($t)
    {
        if ($t == NULL)
            return 'NULL';
        else
            return "'" . $this->DB->escape_string($t) . "'";
    }

    // TODO PkwParser provide callbacks
    // TODO PkwParser first -> build structure of links, second - call them

    function updateGmina($teryt_gminy)
    {

    }

    function insert_into($table, $data) {
        $that = &$this;
        $fields = join(", ", array_keys($data));
        $values = join(", ", array_map(function($v) use($that) {
            return $that->quote($v);
        }, $data));

        return "INSERT INTO $table ($fields) VALUES ($values)";
    }

    /**
     * Przetwarza gminy, ktore nie byly przetwarzane od tygodnia
     */
    function process()
    {
        // oznacz do przetworzenia
        if (!$this->DB->query("UPDATE epf.pl_gminy SET updater_pkw_radni_status = '1' WHERE updater_pkw_radni_last_success < NOW() - INTERVAL 1 week;")) {
            exit(-1);
        }

        $parser = new PkwParser();

        // iteruj po oznaczonych
        while($gmina = $this->DB->selectAssoc("SELECT id, teryt  FROM pl_gminy WHERE updater_pkw_radni_status='1' ORDER BY updater_pkw_radni_last_success ASC LIMIT 1") ) {
            $sql = "UPDATE pl_gminy SET updater_pkw_radni_status = '2' WHERE id = " . $gmina['id'];
            echo $sql . "\n";
            if (!$this->DB->query($sql)) {
                exit(-1);
            }

            $ret = $parser->parse_gmina_teryt($gmina['teryt']);

	        echo "Updating " . $ret['name'] . "\n";

            $rada = &array_find($ret['sections'], function($s) {return strpos($s['type'], 'rada') === 0; });
            $pkw_radni_gminy_count = 0;
            $gmina_status = '3';

            foreach($rada['subsections'] as $okreg_data) {
                $sql = "SELECT * FROM pkw_okregi WHERE kod_terytorialny = " . $this->quote($gmina['teryt']) . ' AND nr_okregu = ' . $this->quote($okreg_data['okreg_num']);
                $okreg = $this->DB->selectAssoc($sql);

                if (!$okreg) {
                    throw new Exception("Nie znaleziono okregu: $sql");
                }

                foreach($okreg_data['people'] AS $radny_data) {
                    if ($radny_data['name'] == 'Mandat nieobsadzony') {
                        continue;
                    }

                    if ($radny_data['rezygnacja_data'] == null) {
                        $pkw_radni_gminy_count++;
                    }

                    $radny = $this->DB->selectAssoc("SELECT * FROM pl_gminy_radni WHERE nazwa_rev = " . $this->quote($radny_data['name']));

                    if (!$radny) {
                        // doszedl w uzupelniajacych najprawdopodobniej
                        $komitet_id = $this->DB->selectValue("SELECT id FROM pkw_komitety WHERE pkw_skrot_nazwy = " . $this->quote($radny_data['komitet']));
                        if ($komitet_id === false || $komitet_id === null) {
                            echo "ERR: Nie znaleziono komitetu pkw_komitety.skrot_nazwy = " . $radny_data['komitet'] . ". Wstawiam dummy (do uzupelnienia pozniej przez wybory_uzupelniajace_link)\n";
                            $sql = "INSERT INTO pkw_komitety (skrot_nazwy) VALUES (".$this->quote($radny_data['komitet']).");";
                            $gmina_status = '4';

                            echo $sql . "\n";
                            if (!$this->DB->query($sql)) {
                                exit(-2);
                            }
                            $komitet_id = $this->DB->insert_id;
                        }

                        $radny_name = explode(" ", $radny_data['name']);
                        $imiona = implode(" ", array_slice($radny_name, 1));
                        $insert_data = array(
                            'src' => 'wybory_uzupelniajace',
                            'src_id' => hexdec(substr(sha1('' . $gmina['id'] . $radny_data['name']),0, 8)), // UNIQUE: src && src_id
                            'wybory_uzupelniajace_link' => $radny_data['wybranie_akcja_wyborcza_link'],
                            'gmina_id' => $gmina['id'],
                            'okreg_id' => $okreg['id'],
                            'komitet_id' => $komitet_id,
                            'nazwisko' => $radny_name[0],
                            'imiona' => $imiona,
                            'nazwa' => $imiona . ' ' . $radny_name[0],
                            'nazwa_rev' => $radny_data['name'],
                            'wybranie_data' => $radny_data['wybranie_data'],
                            'wybranie_przyczyna' => $radny_data['wybranie_akcja_wyborcza'],
                            'rezygnacja_data' => $radny_data['rezygnacja_data'],
                            'rezygnacja_przyczyna' => $radny_data['rezygnacja_podstawa_prawna'],
                        );

                        $sql = $this->insert_into('pl_gminy_radni', $insert_data);

                        echo "$sql\n";
                        if (!$this->DB->query($sql)) {
                            exit(-2);
                        }

                    } else {
                        // sprawdz czy aktualny
                        $rezygnacja_data = extract_date($radny['rezygnacja_data']);
                        $wybranie_data = extract_date($radny['wybranie_data']);

                        $update = false;
                        if ($radny_data['wybranie_data'] != $wybranie_data) {
                            if ($wybranie_data != null) {
                                echo "ERR-pomijam: Uwaga: nadpisalibysmy date wybrania posla " . $radny_data['name'] . ' wartoscia ' . $radny_data['wybranie_data'] . "\n";
                                $gmina_status = '4';
                                continue;
                            } else {
                                $update = true;
                            }
                        }
                        if (isset($radny_data['rezygnacja_data']) && $radny_data['rezygnacja_data'] != $rezygnacja_data) {
                            if ($rezygnacja_data != null) {
                                echo "ERR-pomijam: Uwaga: nadpisalibysmy date rezygnacji posla " . $radny_data['name'] . ' wartoscia ' . $radny_data['rezygnacja_data'] . "\n";
                                $gmina_status = '4';
                                continue;
                            } else {
                                $update = true;
                            }
                        }

                        if ($update) {
                            $sql = "UPDATE pl_gminy_radni SET "
                                . " wybranie_data = ". $this->quote($radny_data['wybranie_data'])
                                . " ,wybranie_przyczyna = ". $this->quote($radny_data['wybranie_akcja_wyborcza'])
                                . " ,wybory_uzupelniajace_link = ". $this->quote($radny_data['wybranie_akcja_wyborcza_link'])
                                . " ,rezygnacja_data = ". $this->quote($radny_data['rezygnacja_data'])
                                . " ,rezygnacja_przyczyna = ". $this->quote($radny_data['rezygnacja_podstawa_prawna'])
                                . " WHERE id = " . $radny['id'];

                            echo $sql . "\n";
                            if (!$this->DB->query($sql)) {
                                exit(-2);
                            }
                        }
                    }
                }
            }

            $sql = "UPDATE pl_gminy_radni SET wybrany = CASE WHEN wybranie_data IS NOT NULL AND rezygnacja_data IS NULL THEN '1' ELSE '0' END WHERE gmina_id = " . $gmina['id'];
            echo $sql . "\n";
            if (!$this->DB->query($sql)) {
                exit(-1);
            }

            // sprawdz, czy ilosc sie zgadza
            $db_ilosc = $this->DB->selectValue("SELECT COUNT(*) FROM pl_gminy_radni WHERE wybrany = '1' AND gmina_id = " . $gmina['id']);
            if ($db_ilosc != $pkw_radni_gminy_count) {
                echo "ERR: Niepoprawna ilosc radnych (gmina_id=".$gmina['id'].")! Wg. pkw jest ich $pkw_radni_gminy_count, wg. naszej bazy $db_ilosc\n";
                echo "     Porownaj: SELECT g.nazwa, o.nr_okregu, r.nazwa_rev, r.* FROM epf.pl_gminy_radni r INNER JOIN pl_gminy g ON (r.gmina_id = g.id) INNER JOIN epf.pkw_okregi o ON (r.okreg_id = o.id) INNER JOIN epf.pkw_komitety k ON (r.komitet_id = k.id) WHERE gmina_id = ".$gmina['id']." AND wybrany = '1' ORDER BY o.nr_okregu, nazwisko;\n";
                echo "     Porownaj: ".$ret['url']."\n";
                $gmina_status = '4';
            }

            // przetworzono gmine, 3 = OK, 4 - alert sprawdz
            $sql = "UPDATE pl_gminy SET updater_pkw_radni_status = ". $this->quote($gmina_status).", updater_pkw_radni_last_success = NOW() WHERE id = " . $gmina['id'];
            echo $sql . "\n";
            if (!$this->DB->query($sql)) {
                exit(-1);
            }

        }
    }
}
