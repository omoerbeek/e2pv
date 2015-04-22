<?php
define('IDCOUNT', N);
define('APIKEY', 'hhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhh');
define('SYSTEMID', 'NNNNNN');

define('LIFETIME', 1);       // see README.md
define('MODE', 'AGGREGATE'); // 'AGGREGATE' or 'SPLIT'
define('EXTENDED', 0);       // Send state data? Uses donation only feature
// AC is default 0. See README.md
define('AC', 0);             // Send DC data or AC (DC * Efficiency)

// If mode is SPLIT, define the Enecsys ID to PVOutput SystemID mapping for each
// inverter.
//$systemid = array(
//  NNNNNNNNN => NNNNNN,
//  NNNNNNNNN => NNNNNN,
//  ...
//);

// If mode is SPLIT, optionally define the Enecsys ID to APIKEY mappings
// If an id is not found, the default APIKEY from above is used.
//$apikey = array(
// NNNNNNNNN => 'hhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhh',
// NNNNNNNNN => 'hhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhh',
//);

// The following inverter ids are ignored (e.g. the neighbours' ones)
$ignored = array(
// NNNNNNNNN,
// ...
);


// Optional MySQL defs, uncomment to enable MySQL inserts, see README.md
//define('MYSQLHOST', 'localhost');
//define('MYSQLUSER', 'myuser');
//define('MYSQLPASSWORD', 'mypw');
//define('MYSQLDB', 'mydbname');
//define('MYSQLPORT', '3306');
?>
