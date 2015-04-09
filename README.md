# e2pv
Listen to Enecsys Gateway posts and sends data to PVOutput

# Optional MySQL support
php needs to be installed with the mysqli extension enabled.

Create a database and define a table:

[pre]
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
[/pre]

You need to set the MYSQL defines in config.php, for example:

define('MYSQLHOST', 'localhost');
define('MYSQLUSER', 'myuser');
define('MYSQLPASSWORD', 'mypw');
define('MYSQLDB', 'mydbname');
define('MYSQLPORT', '3306');
