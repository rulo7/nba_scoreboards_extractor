<?php

/**
 * Created by PhpStorm.
 * User: rulo
 * Date: 15/05/16
 * Time: 12:23
 */
class Manager
{

    public $teams = "ATL,BOS,BRK,CHI,CHO,CLE,DAL,DEN,DET,GSW,HOU,IND,LAC,LAL,MEM,MIA,MIL,MIN,NOP,NYK,OKC,ORL,PHI,PHO,POR,SAC,SAS,TOR,UTA,WAS";

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
        $this->dateUnixStartTime = 1445990400;
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
        foreach (explode(",", $this->teams) as $team){
            $this->analyzeTeamScores($team);
        }
        echo "Aciertos->$this->aciertos\n";
        echo "Fallos->$this->fallos\n";
        $this->sql->close();
    }


    private $aciertos = 0;
    private $fallos = 0;
    public function analyzeTeamScores($teamId){

        $query1 = "SELECT `date`,`match_id`,`TLV`,`T2V`,`T3V` FROM `boxscores`,`matches` WHERE `team_id`='$teamId' AND `match_id`=`link`";
        $result1 = $this->sql->query($query1);

        while ($row = $result1->fetch_assoc()) {
            $matchId = $row["match_id"];
            $date = $row["date"];
            $query2 = "SELECT `team_id`,`TLV`,`T2V`,`T3V` FROM `boxscores` WHERE `team_id`!='$teamId' AND `match_id`='$matchId'";
            $result2 = $this->sql->query($query2)->fetch_array();

            $pts1 = $row["TLV"] + (2 * $row["T2V"]) + ($row["T3V"]);
            $pts2 = $result2["TLV"] + (2 * $result2["T2V"]) + ($result2["T3V"]);

            $score1 = $this->getTeamScore($teamId, $date);
            $score2 = $this->getTeamScore($result2['team_id'], $date);

            if($score1 > $score2 && $pts1 > $pts2)
                $this->aciertos++;
            else if ($score1 < $score2 && $pts1 < $pts2)
                $this->aciertos++;
            else
                $this->fallos++;
        }
    }

    //<editor-fold desc="get score from team">
    public function getProportionRows($dateOfMatch){
        $query  = "SELECT AVG(`TLV`) AS `TLV`, AVG(`TLX`) AS `TLX`, AVG(`T2V`) AS `T2V`,AVG(`T2X`) AS `T2X`,AVG(`T3V`) AS `T3V`, AVG(`T3X`) AS `T3X`, AVG(`ASIST`) AS `ASIST`, AVG(`RO`) AS `RO`, AVG(`RD`) AS `RD`, AVG(`ROB`) AS `ROB`, AVG(`TAP`) AS `TAP`, AVG(`PER`) AS `PER`, AVG(`FAL`) AS `FAL` FROM `boxscores`, `matches` WHERE `link` = `match_id` AND `date` >= $this->dateUnixStartTime AND `date` < $dateOfMatch";
        $result = $this->sql->query($query);
        $row = $result->fetch_assoc();
        $total = 0;
        foreach ($row as $valor) {
            $total += $valor;
        }
        foreach ($row as $key => $column) {
            $weights[$key] = ($column / $total);
        }
        return $weights;
    }

    public function getTeamScore($teamId, $dateOfMatch){
        $query = "SELECT `TLV`,`TLX`,`T2V`,`T2X`,`T3V`,`T3X`,`ASIST`,`RO`,`RD`,`ROB`,`TAP`,`PER`,`FAL` FROM `boxscores`, `matches` WHERE `team_id`='$teamId' AND `match_id`=`link` AND `date` < $dateOfMatch ORDER BY `match_id` DESC LIMIT 5";
        $result = $this->sql->query($query);
        $proportions = $this->getProportionRows($dateOfMatch);
        $r = array();
        while($row = $result->fetch_assoc()){
            foreach ($row as $key => $column) {
                if ($r[$key] == null) {
                    $r[$key] = 0;
                }
                $r[$key] += ($column / $proportions[$key]);
            }
        }

        $total = 0;
        foreach ($r as $key => $column) {
            if($key == 'TLX' ||
                $key == 'T2X' ||
                $key == 'T3X' ||
                $key == 'PER' ||
                $key == 'FAL') {
                $total -= $column;
            }else{
                $total += $column;
            }
        }

        return $total;

    }
    //</editor-fold>

    //<editor-fold desc="save scoreboards">
    public function storeMatchesScoreBoxes($startdate,$endDate){
        $query = "SELECT `link` FROM `matches` WHERE `date` >= $startdate AND `date` < $endDate";
        $result = $this->sql->query($query);
        while ($fila = $result->fetch_assoc()) {
            $match =  $fila['link'];
            $this->getBoxScoresFromMatch($match);
        }
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

    //<editor-fold desc="save match-links">
    public function getMatchesLinks()
    {
        if($this->dateUnixStartTime > $this->dateUnixEndTime) {
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