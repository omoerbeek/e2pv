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

  printf('%s => PVOutput v1=%dWh v2=%dW v5=%.1fC v6=%.1fV' . PHP_EOL,
         date('c'), $e, $p, $temp, $volt);
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

  $i = 0;
  while (true) {
    $str = @socket_read($socket, 1024, PHP_NORMAL_READ);
    if ($str === false || strlen($str) == 0) {
        return;
    }
    if ($i++ % 10 == 0)
      socket_write($socket, "0E0000000000cgAD83\r");
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
        $ACpower = round($v['DCPower'] * $v['Efficiency'], 2);
        $DCVolt = round($v['DCPower'] / $v['DCCurrent'], 2);
        printf('%s DC=%3dW %5.2fV %4.2fA AC=%3dV %6.2fW E=%4.2f T=%2d L=%.3fkWh' .
               PHP_EOL,
               $v['IDDec'], $v['DCPower'], $DCVolt, $v['DCCurrent'],
               $v['ACVolt'], $ACpower,
               $v['Efficiency'], $v['Temperature'], $LifeWh / 1000);
        $total[$v['IDDec']] = array('e' => $LifeWh, 'p' => $v['DCPower'],
          'v' => $v['ACVolt'], 't' => $v['Temperature']);
        if (count($total) != IDCOUNT)
          echo 'Expecing IDCOUNT=' . IDCOUNT . ' IDs, seen ' . count($total) .
           ' IDs' .  PHP_EOL;
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
