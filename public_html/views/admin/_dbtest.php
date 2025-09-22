<?php
ini_set('display_errors',1); error_reporting(E_ALL);
$host='localhost';
$db='db7erxssxpfw7b';
$user='ukg4rxwkkd0is';
$pass='MDsmr@1312@';
try{
  $pdo=new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4",$user,$pass,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
  ]);
  echo "Direct PDO connection: OK";
}catch(Throwable $e){
  echo "Direct PDO failed: ".$e->getMessage();
}