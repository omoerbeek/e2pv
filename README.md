# e2pv
`e2pb.php` is a php script that listens to Enecsys Gateway (V1)
posts and sends data to PVOutput.

First, setup an PVOutput account with API access enabled. Define a system,
making sure that in the Live Settings section Status Interval is set to 10min
and Timezone set to your local time zone. See Section "Aggregation vs
Splitting" if you want it to send data per inverter.

The script is run as a php command line script, no webserver is involved.
The following settings need to be in `php.ini` (adapting the timezone to your
setup):
```php
date.timezone=Europe/Amsterdam
allow_url_fopen = On
```
# Setup
Edit the configuration file `config.php`. 
```php
<?php
define('IDCOUNT', N);
define('APIKEY', 'hhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhh');
define('SYSTEMID', 'NNNNNN');
define('LIFETIME', 1);
define('MODE', 'AGGREGATE');
define('EXTENDED', 0);
?>
```
`IDCOUNT` needs to be set to the number of inverters you have. `APIKEY` and
`SYSTEMID` correspond to your PVOutput api key and System ID.
`LIFETIME` should be set to `0` if your lifetime kWh values produce wrong
values. That seems to happen in some installations when panels are producing 
close to their maximum capacity.
By default, the script aggragtes data from the inverters and sends 
a single record to PVOutput every 10 minutes.
If `EXTENDED` is set to `1`, extra state information is sent to PVOutput.

# Extra state information
Enecsys inverters report state information to the gateway. This state
information can be reported to PVOutput.
This is a donation only feature. Currently three values are sent:
v7 is the count of inverters producing more than zero power, v8 is the count of
inverters with state 0, 1 or 3 and v9 is the count of inverters with a
state unequal to 0, 1 or 3. It is possible to create alerts based on these. 
A typcal alert would trigger on a v9 being 1 or higher. See
http://www.drijf.net/enecsys/extendeddata.jpg for an example configuration.

# Optional MySQL support
php needs to be installed with the mysqli extension enabled.

Create a database and define a table:

```MySQL
CREATE TABLE enecsys (
  ts TIMESTAMP NOT NULL,
  id INT NOT NULL,
  wh INT NOT NULL,
  dcpower INT NOT NULL,
  dccurrent FLOAT NOT NULL,
  efficiency FLOAT NOT NULL,
  acfreq INT NOT NULL,
  acvolt FLOAT NOT NULL,
  temp FLOAT NOT NULL,
  state INT NOT NULL,
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

# Aggregation vs Splitting
By default, the script will collect values from the configured number of
inverters and submit aggregated data to PVOutput. It is possible to
send the data from the individual inverters to PVOutput. Using the "Parent"
feature of PVOutput, a system can be defined that displays the aggregated
data of all inverters. Note that this feature is a *donation only* feature.

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
  120069930 => 123456,
  // three more
);
?>
```
Data for inverter `120069930` will be sent to PVOutput SystemID `123456`, etc.
