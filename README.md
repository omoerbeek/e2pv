# e2pv
Listen to Enecsys Gateway posts and sends data to PVOutput

# Setup
Edit the configuration file config.php. 
```php
<?php
define('IDCOUNT', N);
define('APIKEY', 'hhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhh');
define('SYSTEMID', 'NNNNNN');
?>
```
`IDCOUNT` needs to be set to the number of inverters you have. `APIKEY` and
`SYSTEMID` correspond to your PVOutput api key and System ID.

# Optional MySQL support
php needs to be installed with the mysqli extension enabled.

Create a database and define a table:

```MySQL
CREATE TABLE enecsys (
  ts TIMESTAMP NOT NULL,
  id INT NOT NULL,
  wh INT NOT NULL,
  dcpower INT NOT NULL,
  dccurrent float NOT NULL,
  efficiency float NOT NULL,
  acfreq INT NOT NULL,
  acvolt INT NOT NULL,
  KEY (ts, id)
);
````

You need to set the MYSQL defines in config.php, for example:

```php
define('MYSQLHOST', 'localhost');
define('MYSQLUSER', 'myuser');
define('MYSQLPASSWORD', 'mypw');
define('MYSQLDB', 'mydbname');
define('MYSQLPORT', '3306');
```

# Aggregation
By default, the script will collect values from the configged number of
panels and submit aggrgated data to PVOutput. But it is possible to
send the data from the individual inverters to PVOutput. Using the "Parent"
feauture of PVOutput, a system can be defined that displays the aggragated
data of all panels. Note that this feature is a donation only feature.

An example config.php snippet for a split configuration:

```php
<?php
define('IDCOUNT', 4);
define('APIKEY', 'hhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhh');
define('SYSTEMID', 'NNNNNN');

define('MODE', 'SPLIT'); // 'AGGREGATE' or 'SPLIT'

// If mode is SPLIT, define the Enecsys ID to PVOutput SystemID mapping for each
// inverter.
$systemid = array(
  A => X,
  B => Y,
  C => Z,
  D => W,
);
?>
```
`A-D` are Enecsys IDs, `W-Z` are PVOutput SystemIDs. Data for inverter `A` will
be sent to PVOutput SystemID `X`, etc.
