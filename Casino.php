<?php

class Phergie_Plugin_Casino extends Phergie_Plugin_Abstract
{
    /* DB Settings - Uses Postgresql */
    
    private $dbh = null;
    private $users = array();
    private $startingMoney = null;
    private $loanAmount = null;
    private $db_host = null;
    private $db_user = null;
    private $db_user_pass = null;
    private $db_name = null;   
    private $gamePrices = array(
        'slots' => 25,
        'lottery' => 25
    );
    private $slotWheel = array(
        'symbols' => array('♣', 'ö', 'ʘ', '&', '☺', '▲', '%', '☼', '§', '#'),
        'chance' =>  array( 75,  55,  40,  30,  22,  15,   9,   4,   2,   1),
        'values' =>  array( 10,  20,  30,  40,  50,  60,  70,  80,  90, 100),
        'color' =>   array(  2,   3,   4,   6,   7,   9,  10,  11,  13,   8)
    );
    private $roulette = array(
        'types' => array('red'=>'red','black'=>'black'),
        'spec' => array('even'=>'even','odd'=>'odd'),
        'numbers' => array(
            'red' => array(1,3,5,7,9,12,14,16,18,19,21,23,25,27,30,32,34,36,38),
            'black' => array(2,4,6,8,10,11,13,15,17,20,22,24,26,28,29,31,33,35,37)
        )
    );

    public function onLoad()
    {
        $plugins = $this->getPluginHandler();
        $plugins->getPlugin('Message');
        $this->startingMoney = $this->config['casino.prefs']['startingMoney'];
        $this->loanAmount = $this->config['casino.prefs']['loanAmount'];
        $this->db_host = $this->config['casino.prefs']['db_host'];
        $this->db_user = $this->config['casino.prefs']['db_user'];
        $this->db_user_pass = $this->config['casino.prefs']['db_user_pass'];
        $this->db_name = $this->config['casino.prefs']['db_name'];
        $this->dbh = new PDO("pgsql:host=".$this->db_host.";dbname=".$this->db_name, $this->db_user, $this->db_user_pass);
        $this->dbh->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING );
    }

    private function in_arrayi($needle, $haystack)
    {
        for($h = 0 ; $h < count($haystack) ; $h++)
        {
            $haystack[$h] = strtolower($haystack[$h]);
        }
        return in_array(strtolower($needle),$haystack);
    }

    public function onPrivmsg()
    {
        $source = $this->getEvent()->getSource();
        $nick = $this->getEvent()->getNick();
        
        $msg = $this->plugins->message->getMessage();
        $cmd = count(explode(" ", $msg)) > 1 ? strtolower(strstr($msg, ' ', true)) : $msg;
        $args  = explode(" ", $msg);
        
        if($this->in_arrayi($source, $this->config['casino.channels']))
        {
            if(strpos($cmd,"!") === 0)
            {
                $this->doMode($source,"-v",$nick);
                $this->users[$nick] = array('nick' => $nick, 'time' => time(), 'chan' => $source);
            }

            switch ($cmd)
            {
            case "!register":
                $this->registerUser($nick);
                break;
            case "!loan":
                $this->takeLoan($nick);
                break;
            case "!cash":
            case "!stats":
            case "!money":
                $this->doNotice($nick, $this->getMoneyInfo($nick));
                break;
            case "!rtd":
                $this->rollTheDice($nick, $args, $source);
                break;
            case "!slots":
                $this->playSlots($nick, $source);
                break;
            case "!roulette":
                $this->playRoulette($nick, $args, $source);
                break;
            case "!help":
                $this->showHelp($nick);
                break;
            case "!payloan":
            case "!payoff":
            case "!pay":
            case "!paydebt":
                $this->payDebt($nick, $args[1]);
                break;
            case "!top":
                $this->showTop($source);
                break;
            case "!jackpot":
                $this->getJackpot($source);
                break;
            case "!lottery":
                $this->playLottery($nick, $args, $source);
                break;
            }
            
        }
        
    }
    
    private function payDebt($nick, $cash)
    {
        if(!$this->isRegistered($nick, true)) return ;
        if(is_numeric($cash) && $cash >= 0)
        {
            if($this->hasDebt($nick, $cash))
            {
                $this->giveMoney($nick, -($cash));
                $sth = $this->dbh->prepare("UPDATE userlist SET debt = debt + ? WHERE nick = ?");
                $sth->execute(array($cash, strtolower($nick)));
                $this->doNotice($nick, "You have payed off some debt.");
                $this->addToPot($nick, $cash, "debt");
            }
            else
            {
                $this->doNotice($nick, "You either don't have any debt or you are trying to pay off too much.");
            }
        }
        else
        {
            $this->doNotice($nick, "Invalid number");
        }
    }
    
    public function onTick()
    {
        if(count($this->users) > 0)
        {
            foreach($this->users as $nick)
            {
                if(time() - $nick['time'] >= 3)
                {
                    $this->doMode($nick['chan'],"+v",$nick['nick']);
                    unset($this->users[$nick['nick']]);
                }
            }
        }
    }
    
    private function showTop($chan)
    {
        $sth = $this->dbh->prepare("SELECT nick, (cash+debt) as net_worth FROM userlist ORDER BY net_worth DESC LIMIT 5");
        $sth->execute();
        $top = $sth->fetchAll();
        $return = "=== Top ".count($top)." users === ";
        for($i = 0; $i < count($top); $i++)
        {
            $nick = $top[$i]['nick'];
            $net = $top[$i]['net_worth'];
            $return .= ($i+1).". $nick: $net ";
        }
        $this->doPrivmsg($chan, $return);
    }
    private function getJackpot($chan = null)
    {
        $sth = $this->dbh->prepare("SELECT jackpot FROM settings WHERE id = 0");
        $sth->execute();
        $jackpot = $sth->fetchAll();
        $jackpot = round($jackpot[0]['jackpot'] / 2);
        if($chan != null)
            $this->doPrivmsg($chan, "The current jackpot is \$$jackpot.");
        return $jackpot;
    }
    private function showHelp($nick)
    {
        $this->doNotice($nick, "Casino Help");
        $this->doNotice($nick, "!register - Registers you to the casino.");
        $this->doNotice($nick, "!loan - Lets you take out a loan.");
        $this->doNotice($nick, "!pay <amount> - Lets you pay off some or all of your loan.");
        $this->doNotice($nick, "!top - Shows up 5 top players by their net worth.");
        $this->doNotice($nick, "!rtd <bet> - Rolls two dice. Pays 5:1");
        $this->doNotice($nick, "!slots - Spins 3 slot wheels.");
        $this->doNotice($nick, "!roulette <bet> <[red/black/even/odd]|[#1-38]> <#1-38> - Spins a roulette wheel.");
        $this->doNotice($nick, "!lottery [#1-9] [#1-9] [#1-9] - Play the lottery. Pays the jackpot");
        $this->doNotice($nick, "!jackpot - Shows the current jackpot");
    }
    private function registerUser($nick)
    {
        if($this->isRegistered($nick))
        {
            $this->doNotice($nick, "You are already registered with the casino.");
        }
        else
        {
            $this->doNotice($nick, "You have been registered");
            $sth = $this->dbh->prepare("INSERT into userlist (nick, joindate) VALUES (?,?)");
            $sth->execute(array(strtolower($nick), time()));
            $this->giveMoney($nick, $this->startingMoney, true);
        }
    }

    private function giveMoney($nick, $money, $output = false)
    {
        if(!$this->isRegistered($nick, false)) return ;
            $sth = $this->dbh->prepare("UPDATE userlist SET cash = cash + ? WHERE nick = ?");
            $sth->execute(array($money, strtolower($nick)));
            if($output) $this->doNotice($nick, "You have been given \$$money");
    }

    private function takeLoan($nick)
    {
        if(!$this->isRegistered($nick, true)) return;
        if($this->hasCash($nick, $this->loanAmount)) 
        {
            $this->doNotice($nick, "Sorry, you can only take out a loan if you have less than $".$this->loanAmount);
            return;
        }
        $sth = $this->dbh->prepare("UPDATE userlist SET debt = debt - ?, cash = cash + 500 WHERE nick = ?");
        $sth->execute(array($this->loanAmount, strtolower($nick)));
        $this->doNotice($nick, "Your debt and cash have both increased by " . $this->loanAmount . ". You current standings: " . $this->getMoneyInfo($nick));
    }

    private function getMoneyInfo($nick)
    {
        if(!$this->isRegistered($nick, true)) return;
        $nick = strtolower($nick);
        $sth = $this->dbh->prepare("SELECT cash, debt FROM userlist WHERE nick = ?");
        $sth->execute(array($nick));
        $data = $sth->fetchAll();
        if(count($data) > 0)
        {
            return "Cash: " . $data[0]['cash'] . " - Debt: " . -($data[0]['debt']);
        } 
    }
    
    private function rollTheDice($nick, $args, $chan)
    {
        if(!$this->isRegistered($nick, true)) return;
        
        if(isset($args[1]))
        {
            if(is_numeric($args[1]) && $args[1] > 0)
            {
                if($this->hasCash($nick, $args[1], true))
                {
                    $d1 = rand(1,6);
                    $d2 = rand(1,6);
                    if($d1 == $d2)
                    {
                        $winnings = $args[1]*5;
                        $this->doPrivmsg($chan, "$nick rolled \x02\x031,0[$d1][$d2]\x03\x02 \x02\x038AND WON! \$$winnings\x03\x02");
                        $this->giveMoney($nick, $winnings,false);
                    }
                    else
                    {
                        $this->doNotice($nick, "$nick, you rolled \x02\x031,0[$d1][$d2]\x03\x02 and lost :(");
                        $this->giveMoney($nick, -($args[1]),false);
                        $this->addToPot($nick, $args[1], 'rtd');
                    }
                }
            }
            else
            {
                $this->doNotice($nick, "Your bet needs to be a number greater than zero");
            }
        }
        else
        {
            $this->doNotice($nick, "You need to enter a bet.");
        }
    }
    
    private function hasCash($nick, $cash, $output = false)
    {
        $sth = $this->dbh->prepare("SELECT cash FROM userlist WHERE nick = ?");
        $sth->execute(array(strtolower($nick)));
        $userCash = $sth->fetchAll();
        if($userCash[0]['cash'] >= $cash) return true;
        else
        {
            if($output) $this->doNotice($nick, "You don't have enough money. Consider taking a !loan?");
            return $return;
        }
    }
    private function hasDebt($nick, $debt = -1)
    {
        $sth = $this->dbh->prepare("SELECT debt FROM userlist WHERE nick = ?");
        $sth->execute(array(strtolower($nick)));
        $userDebt = $sth->fetchAll();
        if($userDebt[0]['debt'] <= -($debt)) return true;
        return false;
    }
    private function isRegistered($nick, $output = false)
    {
        $sth = $this->dbh->prepare("SELECT count(*) FROM userlist WHERE nick = ?");
        $sth->execute(array(strtolower($nick)));
        $count = $sth->fetchAll();
        $count = $count[0][0];
        if($count > 0) return true;
        
        if($output) $this->doNotice($nick, "You are not registered with the casino. Try !register");
        return false;
    }
    
    private function addToPot($nick, $cash, $game, $won = false)
    {
        $sth = $this->dbh->prepare("INSERT INTO jackpotlog (nick, game, cash, date) VALUES (?,?,?,?)");
        $sth->execute(array(strtolower($nick), $game, $cash, date("Y-m-d h:i:s",time())));
        
        $sth = $this->dbh->prepare("UPDATE settings set jackpot = jackpot + ? WHERE id = 0");
        $sth->execute(array($cash));
        
        if($won)
        {
            $sth = $this->dbh->prepare("TRUNCATE TABLE jackpotlog;");
            $sth->execute();
        }
    }
    
    private function playSlots($nick, $chan)
    {
        if(!$this->isRegistered($nick, true)) return;
        if(!$this->hasCash($nick, $this->gamePrices['slots'], true)) return;
        
        $wheelOne = rand(1, 100);
        $wheelTwo = rand(1, 100);
        $wheelThree = rand(1, 100);
        for($i = 0; $i < count($this->slotWheel['symbols']); $i++)
        {
            if($wheelOne >= $this->slotWheel['chance'][$i])
            {
                $w1 = $i;
                break;
            }
        }
        for($i = 0; $i < count($this->slotWheel['symbols']); $i++)
        {
            if($wheelTwo >= $this->slotWheel['chance'][$i])
            {
                $w2 = $i;
                break;
            }
        }
        for($i = 0; $i < count($this->slotWheel['symbols']); $i++)
        {
            if($wheelThree >= $this->slotWheel['chance'][$i])
            {
                $w3 = $i;
                break;
            }
        }
        
        $output = "$nick, Your machine shows: ".
        "[\x02\x03".$this->slotWheel['color'][$w1].$this->slotWheel['symbols'][$w1]."\x03\x02]".
        "[\x02\x03".$this->slotWheel['color'][$w2].$this->slotWheel['symbols'][$w2]."\x03\x02]".
        "[\x02\x03".$this->slotWheel['color'][$w3].$this->slotWheel['symbols'][$w3]."\x03\x02]. ";
        
        $this->giveMoney($nick,-($this->gamePrices['slots']),false);
        $this->addToPot($nick, $this->gamePrices['slots'], 'slots');
        
        if($w1 == $w2 && $w2 == $w3)
        {
            //if($w1 == 9) //JACKPOT?
            $winnings = $this->slotWheel['values'][$w1];
            $winnings = ($winnings*5)*$winnings;
            $this->giveMoney($nick,$winnings,false);
            $output .= "WOW! You won \$$winnings!";
            $this->doPrivmsg($chan, $output);
        }
        else if($w1 == $w2 || $w2 == $w3)
        {
            $winnings = $this->slotWheel['values'][$w2];
            $winnings = ($winnings*4);
            $this->giveMoney($nick,$winnings,false);
            $output .= "nice... You won \$$winnings!";
            $this->doNotice($nick, $output);
        }
        else
        {
            $output .= "Sorry, you won nothing.";
            $this->doNotice($nick, $output);
        }
        
        
    }
    
    private function playRoulette($nick, $args, $chan)
    {
        if(!$this->isRegistered($nick, true)) return;
        $color = array_rand($this->roulette['types']);
        $number = $this->roulette['numbers'][$color][array_rand($this->roulette['numbers'][$color])];
        $c = $color == "black" ? "1" : "4";
        $output = "$nick, the roulette ball landed on \x02\x03$c,0[$number $color]\x03\x02. ";
        
        if(isset($args[1]))
        {
            if(is_numeric($args[1]) && $args[1] > 0)
            {
                if($this->hasCash($nick, $args[1], true))
                {
                    if(isset($args[2]))
                    {
                        if(is_numeric($args[2]))
                        {
                            if(isset($args[3]) && in_array(strtolower($args[3]),$this->roulette['types']))
                            {
                                if(in_array($args[2],$this->roulette['numbers'][$args[3]]))
                                {
                                    if($args[2] == $number) //This means they got both the number and color correct
                                    {
                                        $winnings = $args[1]*35;
                                        $output .= "Holy shit, you won \$$winnings! A 35:1 payout!";
                                        $this->giveMoney($nick,$winnings,false);
                                    }
                                    /*(else if($args[3] == $color)
                                    {
                                        $winnings = $args[1];
                                        $output .= "nice, you won \$$winnings";
                                        $this->giveMoney($nick,$args[1],false);
                                    }*/
                                    else
                                    {
                                        $this->giveMoney($nick,-($args[1]),false);
                                        $this->addToPot($nick, $args[1], 'roulette');
                                        $output .= "Sorry you didn't win.";
                                        $this->doNotice($nick, $output);
                                    }
                                }
                                else
                                {
                                    $this->doNotice($nick, "That number does not belong to that color.");
                                }
                            }
                        }
                        else if(in_array(strtolower($args[2]),$this->roulette['types']))
                        {
                            if($args[2] == $color)
                            {
                                $winnings = $args[1];
                                $output .= "nice, you won \$$winnings";
                                $this->giveMoney($nick,$args[1],false);
                                $this->doPrivmsg($chan, $output);
                            }
                            else
                            {
                                $this->giveMoney($nick,-($args[1]),false);
                                $this->addToPot($nick, $args[1], 'roulette');
                                $output .= "Sorry you didn't win.";
                                $this->doNotice($nick, $output);
                            }
                        }
                        else if(in_array(strtolower($args[2]),$this->roulette['spec']))
                        {

                            if(($number % 2 == 0 && $args[2] == "even") || ($number % 2 != 0 && $args[2] == "odd"))
                            {
                                $winnings = $args[1];
                                $output .= "nice, you won \$$winnings";
                                $this->giveMoney($nick,$args[1],false);
                                $this->doNotice($nick, $output);
                            }
                            else
                            {
                                $this->giveMoney($nick,-($args[1]),false);
                                $this->addToPot($nick, $args[1], 'roulette');
                                $output .= "Sorry you didn't win.";
                                $this->doNotice($nick, $output);
                            }
                        }
                        else
                        {
                            $this->doNotice($nick, "Invalid syntax. Usage: !roulette <bet> <[red/black/even/odd]|[#1-38]> <#1-38>");
                        }
                    }
                    else
                    {
                        $this->doNotice($nick, "Invalid syntax. Usage: !roulette <bet> <[red/black]|[#1-38]> <#1-38>");
                    }
                }
            }
            else
            {
                $this->doNotice($nick, "Your bet needs to be a number greater than zero");
            }
            
        }
        else
        {
            $this->doNotice($nick, "You need to enter a bet.");
        }
        
    }
    private function playLottery($nick, $args, $chan)
    {
        $n1 = rand(1,9);
        $n2 = rand(1,9);
        $n3 = rand(1,9);
        $output = "$nick, the winnings numbers were \x02\x034,1[$n1][$n2][$n3]\x03\x02. ";
        
        if(!$this->isRegistered($nick, true)) return;
        if(!$this->hasCash($nick, $this->gamePrices['lottery'], true)) return;

        if(isset($args[1]) && is_numeric($args[1]) && $args[1] > 0 && $args[1] < 10 && isset($args[2]) && is_numeric($args[2]) && $args[2] > 0 && $args[2] < 10 && isset($args[3]) && is_numeric($args[3]) && $args[3] > 0 && $args[3] < 10)
        {
                $this->giveMoney($nick, -($this->gamePrices['lottery']));
                if($n1 == $args[1] && $n2 == $args[2] && $n3 == $args[3]) //Jackpot
                {
                    $jackpot = $this->getJackpot();
                    $this->doPrivmsg($chan, $output . "HOLY MOTHER CANOLI, $nick! You won!!!! You just won \$$jackpot!");
                    $this->giveMoney($nick, $jackpot);
                    $this->addToPot($nick, -($jackpot), "lottery", true);
                }
                else
                {
                    $this->doNotice($nick, $output . "Sorry, you didn't win :(");
                    $this->addToPot($nick, $this->gamePrices['lottery'], "lottery");
                }
        }
        else
        {
            $this->doNotice($nick, "Invalid syntax. Usage: !lottery [#1-9] [#1-9] [#1-9]. ");
        }
        
    }
}