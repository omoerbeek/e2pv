# e2pv
`e2pb.php` is a php script that listens to Enecsys Gateway (V1)
posts and sends data to PVOutput.

First, setup an PVOutput account with API access enabled. Define a system,
making sure that in the Live Settings section "Status Interval" is set to 10min,
"Timezone" is  set to your local time zone and "Adjust Time" is set to "None".
See Section "Aggregation vs Splitting" if you want it to send data per inverter.

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
define('VERBOSE', 0);
define('IDCOUNT', N);
define('APIKEY', 'hhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhh');
define('SYSTEMID', 'NNNNNN');
define('LIFETIME', 1);
define('MODE', 'AGGREGATE');
define('EXTENDED', 0);
define('AC', 0);
// The following inverter ids are ignored (e.g. the neighbours' ones)
$ignored = array(
// NNNNNNNNN,
// ...
);
?>
```
Set `VERBOSE` to 1 if your want the script to print details on what it is doing.
`IDCOUNT` needs to be set to the number of inverters you have. `APIKEY` and
`SYSTEMID` correspond to your PVOutput api key and System ID.
`LIFETIME` should be set to `0` if your lifetime kWh values produce wrong
values. That seems to happen in some installations when panels are producing 
close to their maximum capacity.
By default, the script aggregates data from the inverters and sends 
a single record to PVOutput every 10 minutes.
If `EXTENDED` is set to `1`, extra state information is sent to PVOutput. See
below for details.
By default, the script sends raw DC power data to PVOutput. In a lot of cases
this data reflects the actually power generated. In some cases, the reported
data is e few percent too high. In those cases, define `AC` to `1`.
If an Inverter ID is found in the `$ignored` array, no data for this
inverter will be processed. This can be handy to ignore the
neigbours' inverters which are received by your gateway.

# Extended state information
Enecsys inverters report state information to the gateway. This
state information can be reported to PVOutput using a donation only
feature.  Currently three values are sent: `v7` is the count of
inverters producing more than zero power, `v8` is the count of
inverters with state 0, 1 or 3 and `v9` is the count of inverters
with a state unequal to 0, 1 or 3. It is possible to create alerts
based on these.  A typical alert would trigger on a `v9` being 1 or
higher. See http://www.drijf.net/enecsys/extendeddata.jpg for an
example configuration.

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
inverters and submit aggregated data to PVOutput. It is possible to also
send the data from the individual inverters to PVOutput. 

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

// If mode is SPLIT, optionally define the Enecsys ID to APIKEY mappings
// If an id is not found, the default APIKEY from above is used.
//$apikey = array(
// NNNNNNNNN => 'hhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhh',
// NNNNNNNNN => 'hhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhh',
//);

?>
```
Data for inverter `120069930` will be sent to PVOutput SystemID `123456`, etc.
Aggregated data will also be sent to the main `SYSTEMID`.
If an Enecsys ID is found in the `$apikey` array, output will be
sent to the corresponding apikey, otherwise it will be sent to the
default apikey `APIKEY`.

Older versions of `e2pv.php` required the use of the parent feature
of PVOutput. This is no longer required. Actually, having a parent
structure setup on a system that also gets aggregated info from
this script likely will cause incorrect data to be collected at
PVOutput.
