<?php
  
  namespace StructuredDynamics\structwsf\ws\crud\read\interfaces; 
  
  use \StructuredDynamics\structwsf\framework\Namespaces;  
  use \StructuredDynamics\structwsf\ws\framework\SourceInterface;
  
  class DefaultSourceInterface extends SourceInterface
  {
    function __construct($webservice)
    {   
      parent::__construct($webservice);
      
      $this->compatibleWith = "1.0";
    }
    
    public function processInterface()
    {
      // Make sure there was no conneg error prior to this process call
      if($this->ws->conneg->getStatus() == 200)
      {
        $uris = explode(";", $this->ws->resourceUri);
        $datasets = explode(";", $this->ws->dataset);

        if(count($uris) > 64)
        {
          $this->ws->conneg->setStatus(400);
          $this->ws->conneg->setStatusMsg("Bad Request");
          $this->ws->conneg->setStatusMsgExt($this->ws->errorMessenger->_301->name);
          $this->ws->conneg->setError($this->ws->errorMessenger->_301->id, $this->ws->errorMessenger->ws,
            $this->ws->errorMessenger->_301->name, $this->ws->errorMessenger->_301->description, "",
            $this->ws->errorMessenger->_301->level);

          return;
        }
        
        $subjects = array();

        foreach($uris as $key => $u)
        {
          // Decode potentially encoded ";" character.
          $u = str_ireplace("%3B", ";", $u);
          $d = str_ireplace("%3B", ";", $datasets[$key]);

          $query = "";

          $attributesFilter = "";
          
          // If the structWSF instance uses Virtuoso 6, then we use the new FILTER...IN... statement
          // instead of the FILTER...regex. This makes the queries much faster and fix an issue
          // when the Virtuoso instance has been fixed with the LRL (long read length) path
          if($this->ws->virtuoso_main_version != 6)
          {
            foreach($this->ws->include_attributes_list as $attr)
            {
              $attributesFilter .= $attr."|";
            }
            
            $attributesFilter = trim($attributesFilter, "|");
          }
          else
          {
            foreach($this->ws->include_attributes_list as $attr)
            {
              if($attr != "")
              {
                $attributesFilter .= "<$attr>,";
              }
            }
            
            $attributesFilter = trim($attributesFilter, ",");
          }
          
          if($this->ws->globalDataset === FALSE)
          {
            $d = str_ireplace("%3B", ";", $datasets[$key]);

            // Archiving suject triples
            $query = $this->ws->db->build_sparql_query("
              select ?p ?o (DATATYPE(?o)) as ?otype (LANG(?o)) as ?olang 
              from <" . $d . "> 
              where 
              {
                <$u> ?p ?o.
                ".(
                    $this->ws->virtuoso_main_version != 6 ?
                    ($attributesFilter == "" ? "" : "FILTER regex(str(?p), \"($attributesFilter)\")") : 
                    ($attributesFilter == "" ? "" : "FILTER (?p IN($attributesFilter))")
                  )."
              }", 
              array ('p', 'o', 'otype', 'olang'), FALSE);
          }
          else
          {
            $d = "";

            foreach($datasets as $dataset)
            {
              if($dataset != "")
              {
                $d .= " from named <$dataset> ";
              }
            }

            // Archiving suject triples
            $query = $this->ws->db->build_sparql_query("
              select ?p ?o (DATATYPE(?o)) as ?otype (LANG(?o)) as ?olang ?g 
              $d 
              where 
              {
                graph ?g
                {
                  <$u> ?p ?o.
                }
                ".(
                    $this->ws->virtuoso_main_version != 6 ?
                    ($attributesFilter == "" ? "" : "FILTER regex(str(?p), \"($attributesFilter)\")") : 
                    ($attributesFilter == "" ? "" : "FILTER (?p IN($attributesFilter))")
                  )."
              }", 
              array ('p', 'o', 'otype', 'olang', 'g'), FALSE);
                 
          }

          $resultset = $this->ws->db->query($query);

          if(odbc_error())
          {
            $this->ws->conneg->setStatus(500);
            $this->ws->conneg->setStatusMsg("Internal Error");
            $this->ws->conneg->setStatusMsgExt($this->ws->errorMessenger->_302->name);
            $this->ws->conneg->setError($this->ws->errorMessenger->_302->id, $this->ws->errorMessenger->ws,
              $this->ws->errorMessenger->_302->name, $this->ws->errorMessenger->_302->description, odbc_errormsg(),
              $this->ws->errorMessenger->_302->level);
          }
          
          $g = "";
          
          $subjects[$u] = Array("type" => Array(),
                                "prefLabel" => "",
                                "altLabel" => Array(),
                                "prefURL" => "",
                                "description" => "");

          $nbTriples = 0;                              
                                
          while(odbc_fetch_row($resultset))
          {
            $p = odbc_result($resultset, 1);
            
            $o = $this->ws->db->odbc_getPossibleLongResult($resultset, 2);

            $otype = odbc_result($resultset, 3);
            $olang = odbc_result($resultset, 4);
            
            if($this->ws->lang != "" & $olang != "" && $olang != $this->ws->lang)
            {
              continue;
            }
                      
            if($g == "")
            {
              if($this->ws->globalDataset === FALSE)
              {
                $g = str_ireplace("%3B", ";", $datasets[$key]);
              }
              else
              {
                $g = odbc_result($resultset, 5);
              }
            }

            $objectType = "";
            
            if($olang && $olang != "")
            {
              /* If a language is defined for an object, we force its type to be xsd:string */
              $objectType = "http://www.w3.org/2001/XMLSchema#string";
            }
            else
            {
              $objectType = $otype;
            }
    
            if($this->ws->globalDataset === TRUE) 
            {
              if($p == Namespaces::$rdf."type")
              {
                array_push($subjects[$u]["type"], $o);
              }
              else
              {
                /** 
                * If we are using the globalDataset, there is a possibility that triples get duplicated
                * if the same triples, exists in two different datasets. It is why we have to filter them there
                * so that we don't duplicate them in the serialized dataset.
                */
                $found = FALSE;
                if(isset($subjects[$u][$p]) && is_array($subjects[$u][$p]))
                {
                  foreach($subjects[$u][$p] as $value)
                  {
                    if(isset($value["value"]) && $value["value"] == $o)
                    {
                      $found = TRUE;
                      break;
                    }
                    
                    if(isset($value["uri"]) && $value["uri"] == $o)
                    {
                      $found = TRUE;
                      break;
                    }
                  }
                }     
                
                if($found === FALSE)
                {     
                  if(!isset($subjects[$u][$p]) || !is_array($subjects[$u][$p]))
                  {
                    $subjects[$u][$p] = array();
                  }
                  
                  if($objectType !== NULL)
                  {
                    array_push($subjects[$u][$p], Array("value" => $o, 
                                                        "lang" => (isset($olang) ? $olang : ""),
                                                        "type" => "rdfs:Literal"));
                                                        
                    $nbTriples++;
                  }
                  else
                  {
                    array_push($subjects[$u][$p], Array("uri" => $o, 
                                                        "type" => ""));
                                                        
                    $nbTriples++;
                  }
                }
              }
            }
            else
            {
              if($p == Namespaces::$rdf."type")
              {
                array_push($subjects[$u]["type"], $o);
              }
              else
              {
                if(!isset($subjects[$u][$p]) || !is_array($subjects[$u][$p]))
                {
                  $subjects[$u][$p] = array();
                }
                
                if($objectType !== NULL)
                {
                  array_push($subjects[$u][$p], Array("value" => $o, 
                                                      "lang" => (isset($olang) ? $olang : ""),
                                                      "type" => "rdfs:Literal"));
                                                        
                  $nbTriples++;
                }
                else
                {
                  array_push($subjects[$u][$p], Array("uri" => $o, 
                                                      "type" => ""));
                                                        
                  $nbTriples++;
                }
              }
            }
          }

          if($nbTriples <= 0)
          {
            $this->ws->conneg->setStatus(400);
            $this->ws->conneg->setStatusMsg("Bad Request");
            $this->ws->conneg->setStatusMsgExt($this->ws->errorMessenger->_300->name);
            $this->ws->conneg->setError($this->ws->errorMessenger->_300->id, $this->ws->errorMessenger->ws,
              $this->ws->errorMessenger->_300->name, $this->ws->errorMessenger->_300->description, "",
              $this->ws->errorMessenger->_300->level);

            return;
          }
          
          // Assigning the Dataset relationship
          if($g != "")
          {         
            if(!isset($subjects[$u]["http://purl.org/dc/terms/isPartOf"]) || !is_array($subjects[$u]["http://purl.org/dc/terms/isPartOf"]))
            {
              $subjects[$u]["http://purl.org/dc/terms/isPartOf"] = array();
            }
            
            array_push($subjects[$u]["http://purl.org/dc/terms/isPartOf"], Array("uri" => $g, 
                                                                                 "type" => ""));            
          }        

          // Archiving object triples
          if(strtolower($this->ws->include_linksback) == "true")
          {
            $query = "";

            if($this->ws->globalDataset === FALSE)
            {
              $query = $this->ws->db->build_sparql_query("select ?s ?p from <" . $d . "> where {?s ?p <" . $u . ">.}",
                array ('s', 'p'), FALSE);
            }
            else
            {
              $d = "";

              foreach($datasets as $dataset)
              {
                if($dataset != "")
                {
                  $d .= " from named <$dataset> ";
                }
              }

              $query =
                $this->ws->db->build_sparql_query("select ?s ?p $d where {graph ?g{?s ?p <" . $u . ">.}}", array ('s', 'p'),
                  FALSE);
            }

            $resultset = $this->ws->db->query($query);

            if(odbc_error())
            {
              $this->ws->conneg->setStatus(500);
              $this->ws->conneg->setStatusMsg("Internal Error");
              $this->ws->conneg->setStatusMsgExt($this->ws->errorMessenger->_303 > name);
              $this->ws->conneg->setError($this->ws->errorMessenger->_303->id, $this->ws->errorMessenger->ws,
                $this->ws->errorMessenger->_303->name, $this->ws->errorMessenger->_303->description, odbc_errormsg(),
                $this->ws->errorMessenger->_303->level);
            }

            while(odbc_fetch_row($resultset))
            {
              $s = odbc_result($resultset, 1);
              $p = odbc_result($resultset, 2);

              if(!isset($subjects[$s]))
              {
                $subjects[$s] = array( "type" => array(),
                                       "prefLabel" => "",
                                       "altLabel" => array(),
                                       "prefURL" => "",
                                       "description" => "");
              }
              
              if(!isset($subjects[$s][$p]))
              {
                $subjects[$s][$p] = array();
              }
              
              array_push($subjects[$s][$p], array("uri" => $u, "type" => ""));            
            }

            unset($resultset); 
          }

          // Get reification triples
          if(strtolower($this->ws->include_reification) == "true")
          {
            $query = "";

            if($this->ws->globalDataset === FALSE)
            {
              $query = "  select ?rei_p ?rei_o ?p ?o from <" . $d . "reification/> 
                      where 
                      {
                        ?statement <http://www.w3.org/1999/02/22-rdf-syntax-ns#subject> <"
                . $u
                . ">.
                        ?statement <http://www.w3.org/1999/02/22-rdf-syntax-ns#predicate> ?rei_p.
                        ?statement <http://www.w3.org/1999/02/22-rdf-syntax-ns#object> ?rei_o.
                        ?statement ?p ?o.
                      }";
            }
            else
            {
              $d = "";

              foreach($datasets as $dataset)
              {
                if($dataset != "")
                {
                  $d .= " from named <" . $dataset . "reification/> ";
                }
              }

              $query = "  select ?rei_p ?rei_o ?p ?o $d 
                      where 
                      {
                        graph ?g
                        {
                          ?statement <http://www.w3.org/1999/02/22-rdf-syntax-ns#subject> <"
                . $u
                  . ">.
                          ?statement <http://www.w3.org/1999/02/22-rdf-syntax-ns#predicate> ?rei_p.
                          ?statement <http://www.w3.org/1999/02/22-rdf-syntax-ns#object> ?rei_o.
                          ?statement ?p ?o.
                        }
                      }";
            }

            $query = $this->ws->db->build_sparql_query(str_replace(array ("\n", "\r", "\t"), " ", $query),
              array ('rei_p', 'rei_o', 'p', 'o'), FALSE);

            $resultset = $this->ws->db->query($query);

            if(odbc_error())
            {
              $this->ws->conneg->setStatus(500);
              $this->ws->conneg->setStatusMsg("Internal Error");
              $this->ws->conneg->setStatusMsgExt($this->ws->errorMessenger->_304->name);
              $this->ws->conneg->setError($this->ws->errorMessenger->_304->id, $this->ws->errorMessenger->ws,
                $this->ws->errorMessenger->_304->name, $this->ws->errorMessenger->_304->description, odbc_errormsg(),
                $this->ws->errorMessenger->_304->level);
            }

            while(odbc_fetch_row($resultset))
            {
              $rei_p = odbc_result($resultset, 1);
              $rei_o = $this->ws->db->odbc_getPossibleLongResult($resultset, 2);
              $p = odbc_result($resultset, 3);
              $o = $this->ws->db->odbc_getPossibleLongResult($resultset, 4);

              if($p != "http://www.w3.org/1999/02/22-rdf-syntax-ns#subject"
                && $p != "http://www.w3.org/1999/02/22-rdf-syntax-ns#predicate"
                && $p != "http://www.w3.org/1999/02/22-rdf-syntax-ns#object"
                && $p != "http://www.w3.org/1999/02/22-rdf-syntax-ns#type")
              {
                foreach($subjects[$u][$rei_p] as $key => $value)
                {
                  if(isset($value["uri"]) && $value["uri"] == $rei_o)
                  {
                    if(!isset($subjects[$u][$rei_p][$key]["reify"]))
                    {
                      $subjects[$u][$rei_p][$key]["reify"] = array();
                    }
                    
                    if(!isset($subjects[$u][$rei_p][$key]["reify"][$p]))
                    {
                      $subjects[$u][$rei_p][$key]["reify"][$p] = array();
                    }
                    
                    array_push($subjects[$u][$rei_p][$key]["reify"][$p], $o);
                  }
                }
              }
            }

            unset($resultset);
          }
        }
        
        $this->ws->rset->setResultset(Array("unspecified" => $subjects));
      }      
    }
  }
?>
