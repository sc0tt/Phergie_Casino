Phergie Casino
==============
A Casino bot for the PHP Phergie IRC Client.

Usage
=====
Using it is pretty easy, add 'Casino' in your configuration file under the 
plugins to load, add the configuration options mentioned below, and then 
restart your bot. He work in the channels you specified (He does not autojoin them. Use another plugin for that :P). This script is 
meant to be run in a moderated channel, requiring voice for users to speak.
It has spam protection built in so that users can only speak once per 3 seconds

Configuration
=============
Before using this Casino plugin you will need to setup a database (I have only 
tested this with PostgreSQL but it should not be hard to port to MySQL.

The database structure dump has been provided, but you will need to edit the 
last four lines with the name of the database user that will be accessing the 
database. (You should be creating the database with this user as well.

You will also need to add the following to your configuration file 
(usually Settings.php)

    'casino.channels' => array(); //This must be an array!
    'casino.prefs' => array(
        'db_host' => "", //Database host
        'db_user' => "", //Database User
        'db_user_pass' => "", //Password for above user
        'db_name' => "", //The name of the database
        'startingMoney' => 500, //How much money each user starts with
        'loanAmount' => 500 //The amount given for a loan.
    )

Other
=====
If you ever have to reset the database, you can use the following few queries:

    TRUNCATE TABLE userlist;
    TRUNCATE TABLE jackpotlog;
    UPDATE settings SET jackpot = 0;
    ALTER SEQUENCE userlist_id restart;
    ALTER SEQUENCE jackpotlog_nick_seq restart;
    
All database queries are run with PDO's prepare, this eliminates the 
possibility of SQL injection.
    
