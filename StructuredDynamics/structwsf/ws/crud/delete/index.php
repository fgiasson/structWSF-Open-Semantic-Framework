<?php

/*! @ingroup WsCrud Crud Web Service  */
//@{

/*! @file \StructuredDynamics\structwsf\ws\crud\delete\index.php
    @brief Entry point of a query for the Delete web service
 */
 
include_once("../../../../SplClassLoader.php");  
 
use \StructuredDynamics\structwsf\ws\crud\delete\CrudDelete;
use \StructuredDynamics\structwsf\ws\framework\Logger; 

// Don't display errors to the users. Set it to "On" to see errors for debugging purposes.
ini_set("display_errors", "Off"); 

ini_set("memory_limit", "64M");

if ($_SERVER['REQUEST_METHOD'] != 'GET') 
{
    header("HTTP/1.1 405 Method Not Allowed");  
    die;
}

// Interface to use for this query
$interface = "default";

if(isset($_GET['interface']))
{
  $interface = $_GET['interface'];
}

// Version of the requested interface to use for this query
$version = "";

if(isset($_GET['version']))
{
  $version = $_GET['version'];
}

// IP being registered
$registered_ip = "";

if(isset($_GET['registered_ip']))
{
  $registered_ip = $_GET['registered_ip'];
}

// Dataset where to index the resource
$dataset = "";

if(isset($_GET['dataset']))
{
  $dataset = $_GET['dataset'];
}

// URI of the resource to delete
$uri = "";

if(isset($_GET['uri']))
{
  $uri = $_GET['uri'];
}

$mtime = microtime();
$mtime = explode(' ', $mtime);
$mtime = $mtime[1] + $mtime[0];
$starttime = $mtime;

$start_datetime = date("Y-m-d h:i:s");

$requester_ip = "0.0.0.0";

if(isset($_SERVER['REMOTE_ADDR']))
{
  $requester_ip = $_SERVER['REMOTE_ADDR'];
}

$parameters = "";

if(isset($_SERVER['REQUEST_URI']))
{
  $parameters = $_SERVER['REQUEST_URI'];

  $pos = strpos($parameters, "?");

  if($pos !== FALSE)
  {
    $parameters = substr($parameters, $pos, strlen($parameters) - $pos);
  }
}
elseif(isset($_SERVER['PHP_SELF']))
{
  $parameters = $_SERVER['PHP_SELF'];
}

$ws_cruddelete = new CrudDelete($uri, $dataset, $registered_ip, $requester_ip, $interface, $version);

$ws_cruddelete->ws_conneg((isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : ""), 
                          (isset($_SERVER['HTTP_ACCEPT_CHARSET']) ? $_SERVER['HTTP_ACCEPT_CHARSET'] : ""), 
                          (isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : ""), 
                          (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : "")); 

$ws_cruddelete->process();

$ws_cruddelete->ws_respond($ws_cruddelete->ws_serialize());

$mtime = microtime();
$mtime = explode(" ", $mtime);
$mtime = $mtime[1] + $mtime[0];
$endtime = $mtime;
$totaltime = ($endtime - $starttime);

if($ws_cruddelete->isLoggingEnabled())
{
  $logger = new Logger("crud_delete", 
                       $requester_ip,
                       "?uri=" . $uri . 
                       "&dataset=" . $dataset . 
                       "&registered_ip=" . $registered_ip . 
                       "&requester_ip=$requester_ip",
                       (isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : ""),
                       $start_datetime, 
                       $totaltime, 
                       $ws_cruddelete->pipeline_getResponseHeaderStatus(),
                       (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ""));
}

//@}

?>