<?php
/*
 * Copyright (c) 2015 Otto Moerbeek <otto@drijf.net>
 *
 * Permission to use, copy, modify, and distribute this software for any
 * purpose with or without fee is hereby granted, provided that the above
 * copyright notice and this permission notice appear in all copies.
 *
 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
 * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
 * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
 * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
 * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
 * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
 */

require_once 'config.php';
// Example to put in config.php:
// define('IDCOUNT', 4);
// define('APIKEY', 'PVOutput hex api key');
// define('SYSTEMID', 'PVOutput system id');

function fatal($msg) {
  echo $msg . ': ' . socket_strerror(socket_last_error()) . PHP_EOL;
  exit(1);
}

$total = array();
$last = 0;

function submit($total) {
  $e = 0.0;
  $p = 0.0;
  $temp = 0.0;
  $volt = 0.0;
  foreach ($total as $t) {
    $e += $t['e'];
    $p += $t['p'];
    $temp += $t['t'];
    $volt += $t['v'];
  }
  $temp /= count($total);
  $volt /= count($total);

  echo date('c') . 'POST to PVOutput v1=' . $e . 'Wh v2=' . $p . 'W v5=' .
    $temp . 'C v6=' .  $volt . 'V' . PHP_EOL;
  $time = time();
  $data = array('d' => strftime('%Y%m%d', $time),
    't' => strftime('%H:%M', $time),
    'v1' => $e,
    'v2' => $p,
    'v5' => $temp,
    'v6' => $volt,
    'c1' => 1);
  $headers = array(
    "Content-type: application/x-www-form-urlencoded",
    'X-Pvoutput-Apikey: ' . APIKEY,
    'X-Pvoutput-SystemId: ' . SYSTEMID);
  $url = 'http://pvoutput.org/service/r2/addstatus.jsp';
  
  $data = http_build_query($data);
  $ctx = array('http' => array(
    'method' => 'POST',
    'header' => $headers,
    'content' => $data));
  $context = stream_context_create($ctx);
  $fp = fopen($url, 'r', false, $context);
  if (!$fp)
    echo 'POST failed, check your APIKEY and SYSTEMID' . PHP_EOL;
  else
    fclose($fp);
}

function process($socket) {
  global $total;
  global $last;

  while (true) {
    $str = @socket_read($socket, 1024, PHP_NORMAL_READ);
    if ($str === false || strlen($str) == 0) {
        return;
    }
    $str = str_replace(array("\n", "\r"), "", $str);
    //echo $str . PHP_EOL;
    $pos = strpos($str, 'WS');
    if ($pos !== false) {
        $sub = substr($str, $pos + 3);
        $sub = str_replace(array('-', '_' , '*'), array('+', '/' ,'='), $sub);
        //echo strlen($sub) . ' ' . $sub . PHP_EOL;
        $bin = base64_decode($sub);
        if (strlen($bin) != 42)
          continue;

        $v = unpack('VIDDec/c19dummy/nDCCurrent/nDCPower/nEfficiency/cACFreq/' .
          'nACVolt/cTemperature/nWh/nkWh', $bin);
        $v['DCCurrent'] *= 0.025;
        $v['Efficiency'] *= 0.001;
        $LifeWh = $v['kWh'] * 1000 + $v['Wh'];
        $ACpower = $v['DCPower'] * $v['Efficiency'];
        $DCVolt = round($v['DCPower'] / $v['DCCurrent'], 2);
        echo $v['IDDec'] . ' DC=' . $v['DCPower']  . 'W ' .
          $DCVolt . 'V ' . $v['DCCurrent'] . 'A ' . 'AC=' .
          $v['ACVolt'] . 'V ' .  $v['ACFreq'] . 'Hz '  .  $ACpower . 'W ' .
          'E=' . $v['Efficiency'] .  ' T=' .  $v['Temperature'] .
          'C L=' . $LifeWh/1000 . 'kWh' . PHP_EOL;
        $total[$v['IDDec']] = array('e' => $LifeWh, 'p' => $v['DCPower'],
          'v' => $v['ACVolt'], 't' => $v['Temperature']);
        if (count($total) != IDCOUNT)
          echo 'Expecing IDCOUNT=' . IDCOUNT . ' IDs, seen ' . count($total) .
           ' IDs sofar' .  PHP_EOL;
        if ($last < time() - 600 && count($total) == IDCOUNT) {
          submit($total);
          $last = time();
        }
    }
  }
}

function setup() {
  $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
  if ($socket === false)
    fatal('socket_create');
  socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
  $ok = socket_bind($socket, '0.0.0.0', 5040);
  if (!$ok) 
    fatal('socket_bind');
  $ok = socket_listen($socket);
  if (!$ok)
    fatal('socket_listen');
  return $socket;
}

function loop($socket) {
  while (true) {
    $client = socket_accept($socket);
    if (!$client)
      fatal('socket_accept');
    process($client);
    socket_shutdown($client);
  }
}

$socket = setup();
loop($socket);
socket_close($socket);

?>        
