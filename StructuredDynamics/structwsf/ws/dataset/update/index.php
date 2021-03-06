<?php

/*! @ingroup WsDataset Dataset Management Web Service  */
//@{

/*! @file \StructuredDynamics\structwsf\ws\dataset\update\index.php
    @brief Entry point of a query for the Dataset Update web service
 */

include_once("../../../../SplClassLoader.php");  
 
use \StructuredDynamics\structwsf\ws\dataset\update\DatasetUpdate;
use \StructuredDynamics\structwsf\ws\framework\Logger;  

// Don't display errors to the users. Set it to "On" to see errors for debugging purposes.
ini_set("display_errors", "Off"); 

ini_set("memory_limit", "64M");

if ($_SERVER['REQUEST_METHOD'] != 'POST') 
{
    header("HTTP/1.1 405 Method Not Allowed");  
    die;
}

// Interface to use for this query
$interface = "default";

if(isset($_POST['interface']))
{
  $interface = $_POST['interface'];
}

// Version of the requested interface to use for this query
$version = "";

if(isset($_POST['version']))
{
  $version = $_POST['version'];
}

// URI for the dataset
$uri = "";

if(isset($_POST['uri']))
{
  $uri = $_POST['uri'];
}

// Title of the dataset (optional)
$title = "";

if(isset($_POST['title']))
{
  $title = $_POST['title'];

  if($title == "")
  {
    $title = "-delete-";
  }
}

// Description of the dataset (optional)
$description = "";

if(isset($_POST['description']))
{
  $description = $_POST['description'];

  if($description == "")
  {
    $description = "-delete-";
  }
}

// List of contributor URIs (optional)
$contributors = "";

if(isset($_POST['contributors']))
{
  $contributors = $_POST['contributors'];

  if($contributors == "")
  {
    $contributors = "-delete-";
  }
}

// Modification date (optional)
$modified = "";

if(isset($_POST['modified']))
{
  $modified = $_POST['modified'];

  if($modified == "")
  {
    $modified = "-delete-";
  }
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

// Optional IP
$registered_ip = "";

if(isset($_POST['registered_ip']))
{
  $registered_ip = $_POST['registered_ip'];
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

$ws_du = new DatasetUpdate($uri, $title, $description, $contributors, $modified, 
                           $registered_ip, $requester_ip, $interface, $version);

$ws_du->ws_conneg((isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : ""), 
                  (isset($_SERVER['HTTP_ACCEPT_CHARSET']) ? $_SERVER['HTTP_ACCEPT_CHARSET'] : ""), 
                  (isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : ""), 
                  (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : "")); 

$ws_du->process();

$ws_du->ws_respond($ws_du->ws_serialize());

$mtime = microtime();
$mtime = explode(" ", $mtime);
$mtime = $mtime[1] + $mtime[0];
$endtime = $mtime;
$totaltime = ($endtime - $starttime);

if($ws_du->isLoggingEnabled())
{
  $logger = new Logger("dataset_update", 
                       $requester_ip,
                       "?uri=" . $uri . 
                       "&title=" . substr($title, 0, 64) . 
                       "&description=" . substr($description, 0, 64) . 
                       "&modified=" . $modified . 
                       "&requester_ip=$requester_ip", 
                       (isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : ""),
                       $start_datetime, 
                       $totaltime,
                       $ws_du->pipeline_getResponseHeaderStatus(), 
                       (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ""));
}

//@}

?>