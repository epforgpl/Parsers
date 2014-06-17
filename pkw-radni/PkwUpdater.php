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
            $sql = "UPDATE pl_gminy SET updater_pkw_radni_status = 2 WHERE id = " . $gmina['id'];
            echo $sql . "\n";
            if (!$this->DB->query($sql)) {
                exit(-1);
            }

            $ret = $parser->parse_gmina_teryt($gmina['teryt']);


	        echo "Updating " . $ret['name'] . "\n";

            $rada = &array_find($ret['sections'], function($s) {return strpos($s['type'], 'rada') === 0; });
            foreach($rada['subsections'] as $okreg_data) {
                $okreg = $this->DB->selectAssoc("SELECT * FROM pkw_okregi WHERE kod_terytorialny = " . $this->quote($gmina['teryt']) . ' AND nr_okregu = ' . $this->quote($okreg_data['okreg_num']));

                foreach($okreg_data['people'] AS $radny_data) {
                    if ($radny_data['name'] == 'Mandat nieobsadzony') {
                        continue;
                    }

                    $radny = $this->DB->selectAssoc("SELECT * FROM pl_gminy_radni WHERE nazwa_rev = " . $this->quote($radny_data['name']));

                    if (!$radny) {
                        // doszedl w uzupelniajacych najprawdopodobniej
                        $komitet_id = $this->DB->selectValue("SELECT id FROM pkw_komitety WHERE skrot_nazwy = " . $this->quote($radny_data['komitet']));
                        if ($komitet_id === false) {
                            throw new Exception("Nie znaleziono komitetu pkw_komitety.skrot_nazwy = " . $radny_data['komitet']);
                        }

                        $radny_name = explode(" ", $radny_data['name']);
                        $imiona = implode(" ", array_slice($radny_name, 1));
                        $insert_data = array(
                            'src' => 'wybory_uzupelniajace',
                            'wybory_uzupelniajace_link' => $radny_data['wybranie_akcja_wyborcza_link'],
                            'gmina_id' => $radny_data['wybranie_akcja_wyborcza_link'],
                            'okreg_id' => $okreg['id'],
                            'komitet_id' => $komitet_id,
                            'nazwisko' => $radny_name[0],
                            'imiona' => $imiona,
                            'nazwa' => $imiona . ' ' . $radny_name[0],
                            'nazwa_rev' => $radny_name[0] . ' ' . $imiona,
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
                                throw new Exception("Uwaga: nadpisalibysmy date wybrania posla " . $radny_data['name'] . ' wartoscia ' . $radny_data['wybranie_data']);
                            } else {
                                $update = true;
                            }
                        }
                        if (isset($radny_data['rezygnacja_data']) && $radny_data['rezygnacja_data'] != $rezygnacja_data) {
                            if ($rezygnacja_data != null) {
                                throw new Exception("Uwaga: nadpisalibysmy date rezygnacji posla " . $radny_data['name'] . ' wartoscia ' . $radny_data['rezygnacja_data']);
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

            // TODO oznacz wybrano = 0/1

            // przetworzono gmine, 3 = OK
            $sql = "UPDATE pl_gminy SET updater_pkw_radni_status = 3 WHERE id = " . $gmina['id'];
            echo $sql . "\n";
            if (!$this->DB->query($sql)) {
                exit(-1);
            }

        }
    }
}
