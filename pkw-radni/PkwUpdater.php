<?php

require_once("PkwParser.php");

class PkwUpdater
{
    private $ctx, $DB;

    function __construct($ctx)
    {
        $this->ctx = $ctx;
        $this->DB = $ctx->DB;
    }

    private function quote($t)
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

    /**
     * Przetwarza gminy, ktore nie byly przetwarzane od tygodnia
     */
    function process()
    {
        // oznacz do przetworzenia
        echo ">> UPDATE epf.pl_gminy SET updater_pkw_radni_status = '1' WHERE updater_pkw_radni_last_success < NOW() - INTERVAL 1 week;\n";
//        if (!$this->DB->query("UPDATE epf.pl_gminy SET updater_pkw_radni_status = '1' WHERE updater_pkw_radni_last_success < NOW() - INTERVAL 1 week;")) {
//            exit(-1);
//        }

        $parser = new PkwParser();

        // iteruj po oznaczonych
        while($gmina = $this->DB->selectAssoc("SELECT id, teryt  FROM pl_gminy WHERE updater_pkw_radni_status='0' ORDER BY updater_pkw_radni_last_success ASC LIMIT 1") ) {
            echo ">> UPDATE pl_gminy SET updater_pkw_radni_status = 2 WHERE id = " . $gmina['id'] . "\n";
//            if (!$this->DB->query("UPDATE pl_gminy SET updater_pkw_radni_status = 2 WHERE id = " . $gmina['id'])) {
//                exit(-1);
//            }

            $ret = $parser->parse_gmina_teryt($gmina['teryt']);

          echo var_export($ret);  
	  echo "Updating " . $ret['name'] . "\n";

            $rada = &array_find($ret['sections'], function($s) {return strpos($s['type'], 'rada') === 0; });
            foreach($rada['subsections'] as $okreg_data) {
                $okreg = $this->DB->selectAssoc("SELECT * FROM pkw_okregi WHERE kod_terytorialny = " . $this->quote($gmina['teryt']) . ' AND nr_okregu = ' . $this->quote($okreg_data['okreg_num']));

                foreach($okreg_data['people'] AS $radny_data) {
                    $radny = $this->DB->selectAssoc("SELECT * FROM pl_gminy_radni WHERE nazwa_rev = " . $this->quote($radny_data['name']));

                    if (!$radny) {
                        // doszedl w uzupelniajacych?
                        // TODO to sie na razie nie powinno zdarzyc, bo uzupelniajacych nie przetwarzamy
                        // okreg_id
                        // komitet_id pkw_komitety WHERE skrot_nazwy = $radny_data['komitet']
                        throw new Exception("Doszedl w uzupelniajacych?! Nie znalezino " . $radny_data['name'] . "\n");

                    } else {
                        // sprawdz czy aktualny
                        // TODO data to string
                        $rezygnacja_data = $radny->rezygnacja_data;
                        $wybranie_data = $radny->wybranie_data;

                        $update = false;
                        if ($radny_data['wybranie_data'] != $wybranie_data) {
                            if ($wybranie_data != null) {
                                throw new Exception("Uwaga: nadpisalibysmy date wybrania posla " . $radny_data['name'] . ' wartoscia ' . $radny_data['wybranie_data']);
                            } else {
                                $update = true;
                            }
                        }
                        if ($radny_data['rezygnacja_data'] != $rezygnacja_data) {
                            if ($wybranie_data != null) {
                                throw new Exception("Uwaga: nadpisalibysmy date rezygnacji posla " . $radny_data['name'] . ' wartoscia ' . $radny_data['rezygnacja_data']);
                            } else {
                                $update = true;
                            }
                        }

                        if ($update) {
                            $sql = "UPDATE pl_gminy_radni SET "
                                . " wybranie_data = ". $this->quote($radny_data['wybranie_data'])
                                . " wybranie_przyczyna = ". $this->quote($radny_data['wybranie_akcja_wyborcza'])
                                . " rezygnacja_data = ". $this->quote($radny_data['rezygnacja_data'])
                                . " rezygnacja_przyczyna = ". $this->quote($radny_data['rezygnacja_podstawa_prawna'])
                                . " WHERE id = " . $radny->id;

                            echo ">> " . $sql . "\n";
//                            if (!$this->DB->query()) {
//                                exit(-2);
//                            }
                        }
                    }
                }
            }
        }
    }
}
