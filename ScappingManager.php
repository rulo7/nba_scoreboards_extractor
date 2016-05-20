<?php

/**
 * Created by PhpStorm.
 * User: rulo
 * Date: 15/05/16
 * Time: 12:23
 */
class ScappingManager
{

    public $sql;
    public $dateUnixStartTime;
    public $dateUnixEndTime;
    public $dayInSeconds = 86400;

    /**
     * ScappingManager constructor.
     */
    public function __construct(){
        $this->sql = $this->mySqliInit();
        error_reporting(E_ERROR | E_PARSE);
        $this->dateUnixEndTime = time();
        $this->dateUnixStartTime = 1451606400;
    }


    function mySqliInit(){
        $sql = mysqli_connect("localhost", "root", "", "nba_prediction", 3306);
        if (mysqli_connect_errno($sql)) {
            echo "Fallo al conectar a MySQL: " . mysqli_connect_error();
            return null;
        }
        return $sql;
    }

    public function execute(){
        //$this->getMatchesLinks();
        //$this->storeMatchesScoreBoxes();
    }

    //<editor-fold desc="scoreboards">
    public function storeMatchesScoreBoxes(){
        $query = "SELECT `link` FROM `matches`";
        $result = $this->sql->query($query);
        while ($fila = $result->fetch_assoc()) {
            $match =  $fila['link'];
            echo $match . "\n";
            $this->getBoxScoresFromMatch($match);
        }

        $this->sql->close();
        echo "fin";
    }

    public function getBoxScoresFromMatch($match){
        $url = "http://www.basketball-reference.com" . $match;
        $html = file_get_contents($url);
        $this->storeScoreBoards($html, $match);
    }

    public function storeScoreBoards($html, $match_id){

        $dom = new DomDocument();
        $dom->loadHTML($html);
        $finder = new DomXPath($dom);
        $classname="bold_text stat_total";
        $scoreNodes = $finder->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' $classname ')]");
        preg_match_all('*[A-Z]{3}_basic*', $html, $teamNames);

        $team1 = str_replace("_basic","",$teamNames[0][0]);
        $team2 = str_replace("_basic","",$teamNames[0][2]);

        $this->storescoreBoardFromTeam($match_id,$team1,explode("   ", $scoreNodes->item(0)->textContent));
        $this->storescoreBoardFromTeam($match_id,$team2,explode("   ", $scoreNodes->item(2)->textContent));
    }

    public function storescoreBoardFromTeam($match_id,$teamID,$values){

        $t2v = $values[2];
        $t2x = $values[3]-$values[2];
        $t3v = $values[5];
        $t3x = $values[6]-$values[5];
        $tlv = $values[8];
        $tlx = $values[9]-$values[8];
        $ro = $values[11];
        $rd = $values[12];
        $asist = $values[14];
        $rob = $values[15];
        $tap = $values[16];
        $per = $values[17];
        $fal = $values[18];


        $sqlQuery = "INSERT INTO `boxscores`(`match_id`, `team_id`, `TLV`, `TLX`, `T2V`, `T2X`, `T3V`, `T3X`, `ASIST`, `RO`, `RD`, `ROB`, `TAP`, `PER`, `FAL`) 
        VALUES ('$match_id', '$teamID', $tlv, $tlx, $t2v, $t2x, $t3v, $t3x, $asist, $ro, $rd, $rob, $tap, $per, $fal)";
        if($this->sql->query($sqlQuery))
            echo "saved -> " . $match_id ."-" .$teamID . "\n";
        else {
            echo $sqlQuery;
            echo mysqli_error($this->sql);
        }
    }
    //</editor-fold>

    //<editor-fold desc="match-links">
    public function getMatchesLinks()
    {
        if($this->dateUnixStartTime > $this->dateUnixEndTime) {
            $this->sql->close();
            echo "fin";
            return;
        }

        $url = "http://www.basketball-reference.com/boxscores/index.cgi?month=-month-&day=-day-&year=-year-";
        $url = str_replace("-day-",date("j", $this->dateUnixStartTime), $url);
        $url = str_replace("-month-",date("n", $this->dateUnixStartTime),$url);
        $url = str_replace("-year-",date("Y", $this->dateUnixStartTime),$url);
        echo "url -> " . $url . "\n";
        $html = file_get_contents($url);
        $this->storeMatches($html);
        $this->dateUnixStartTime = $this->dateUnixStartTime + $this->dayInSeconds;
        $this->getMatchesLinks();
    }

    function storeMatches($html)
    {
        $sqlQuery = "INSERT INTO matches (link, date) VALUES('-link-',-date-) ON DUPLICATE KEY UPDATE link='-link-', date=-date-";
        $pattern = "#/boxscores/[A-Z0-9]*\\.html#";
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $tags = $dom->getElementsByTagName("a");
        foreach ($tags as $tag) {
            $href = $tag->attributes->getNamedItem("href")->nodeValue;
            if (preg_match($pattern, $href)) {
                $query = str_replace("-date-",$this->dateUnixStartTime,str_replace("-link-", $href, $sqlQuery));
                if($this->sql->query($query))
                    echo "saved -> " . $href . "\n";
            }
        }
    }
    //</editor-fold>

}