<?php
  
  namespace StructuredDynamics\structwsf\ws\ontology\update\interfaces; 
  
  use \StructuredDynamics\structwsf\framework\Namespaces;  
  use \StructuredDynamics\structwsf\ws\framework\SourceInterface;
  use \StructuredDynamics\structwsf\ws\framework\OWLOntology;
  use \StructuredDynamics\structwsf\ws\ontology\read\OntologyRead;
  use \StructuredDynamics\structwsf\ws\crud\delete\CrudDelete;
  use \StructuredDynamics\structwsf\ws\crud\create\CrudCreate;
  use \StructuredDynamics\structwsf\ws\crud\update\CrudUpdate;
  use \ARC2;
  use \Exception;
  
  class DefaultSourceInterface extends SourceInterface
  {
    /** Requester's IP used for request validation */
    private $requester_ip = "";
    
    private $OwlApiSession = null;    
    
    function __construct($webservice)
    {   
      parent::__construct($webservice);
      
      $this->compatibleWith = "1.0";
    }
    
    /**
    * 
    *  
    * @author Frederick Giasson, Structured Dynamics LLC.
    */
    private function getOntologyReference()
    {
      try
      {
        $this->ws->ontology = new OWLOntology($this->ws->ontologyUri, $this->OwlApiSession, TRUE);
      }
      catch(Exception $e)
      {
        $this->ws->returnError(400, "Bad Request", "_300");
      }    
    }   
    
    private function in_array_r($needle, $haystack) 
    {
      foreach($haystack as $item) 
      {
        if($item === $needle || (is_array($item) && $this->in_array_r($needle, $item))) 
        {
          return TRUE;
        }
      }

      return FALSE;
    }
    
    /**
    * 
    *  
    * @author Frederick Giasson, Structured Dynamics LLC.
    */
    private function initiateOwlBridgeSession()
    {
      // Starts the OWLAPI process/bridge
      require_once($this->ws->owlapiBridgeURI);

      // Create the OWLAPI session object that could have been persisted on the OWLAPI instance.
      // Second param "false" => we re-use the pre-created session without destroying the previous one
      // third param "0" => it nevers timeout.
      if($this->OwlApiSession == null)
      {
        $this->OwlApiSession = java_session("OWLAPI", false, 0);
      }    
    }    
    
    /**
    * @author Frederick Giasson, Structured Dynamics LLC.
    */
    private function isValid()
    {
      // Make sure there was no conneg error prior to this process call
      if($this->ws->conneg->getStatus() == 200)
      {
        $this->ws->validateQuery();

        // If the query is still valid
        if($this->ws->conneg->getStatus() == 200)
        {
          return(TRUE);
        }
      }
      
      return(FALSE);    
    }    
    
    /**
    * Tag an ontology as being saved. This simply removes the "ontologyModified" annotation property.
    * The ontology has to be saved, on some local system, of the requester. That system has to 
    * export the ontology after calling "saveOntology", and save its serialization somewhere.
    * 
    *  
    * @author Frederick Giasson, Structured Dynamics LLC.
    */
    public function saveOntology()
    {
      $this->initiateOwlBridgeSession();

      $this->getOntologyReference();
      
      if($this->isValid())      
      {
        // Remove the "ontologyModified" annotation property value
        $this->ws->ontology->removeOntologyAnnotation("http://purl.org/ontology/wsf#ontologyModified", "true");
      }
    }
    
    /**
    * Create a new, or update an existing entity based on the input RDF document.
    * 
    * @param mixed $document
    * @param mixed $advancedIndexation
    *  
    * @author Frederick Giasson, Structured Dynamics LLC.
    */
    public function createOrUpdateEntity($document, $advancedIndexation)
    {
      $this->initiateOwlBridgeSession();

      $this->getOntologyReference();
      
      if($this->isValid())      
      {
        // Now read the RDF file that we got as input to update the ontology with it.
        // Basically, we list all the entities (classes, properties and instance)
        // and we update each of them, one by one, in both the OWLAPI instance
        // and structWSF if the advancedIndexation is enabled.
        include_once("../../framework/arc2/ARC2.php");
        $parser = ARC2::getRDFParser();
        $parser->parse($this->ws->ontology->getBaseUri(), $document);
        $rdfxmlSerializer = ARC2::getRDFXMLSerializer();
        
        $resourceIndex = $parser->getSimpleIndex(0);

        if(count($parser->getErrors()) > 0)
        {
          $errorsOutput = "";
          $errors = $parser->getErrors();

          foreach($errors as $key => $error)
          {
            $errorsOutput .= "[Error #$key] $error\n";
          }

          $this->ws->conneg->setStatus(400);
          $this->ws->conneg->setStatusMsg("Bad Request");
          $this->ws->conneg->setError($this->ws->errorMessenger->_301->id, $this->ws->errorMessenger->ws,
            $this->ws->errorMessenger->_301->name, $this->ws->errorMessenger->_301->description, $errorsOutput,
            $this->ws->errorMessenger->_301->level);

          return;
        }
        
        // Get all entities
        foreach($resourceIndex as $uri => $description)
        {         
          $types = array();
          $literalValues = array();
          $objectValues = array();    
         
          foreach($description as $predicate => $values)
          {
            switch($predicate)
            {
              case Namespaces::$rdf."type":
                foreach($values as $value)
                {
                  array_push($types, $value["value"]);
                }
              break;
              
              default:
                foreach($values as $value)
                {
                  if($value["type"] == "literal")
                  {
                    if(!is_array($literalValues[$predicate]))
                    {
                      $literalValues[$predicate] = array();
                    }
                    
                    array_push($literalValues[$predicate], $value["value"]);  
                  }
                  else
                  {
                    if(!is_array($objectValues[$predicate]))
                    {
                      $objectValues[$predicate] = array();
                    }
                    
                    array_push($objectValues[$predicate], $value["value"]);                      
                  }
                }                
              break;
            }
          }
   
          // Call different API calls depending what we are manipulating
          if($this->in_array_r(Namespaces::$owl."Ontology", $description[Namespaces::$rdf."type"]))
          {
            $this->ws->ontology->updateOntology($literalValues, $objectValues); 
            
            // Make sure advanced indexation is off when updating an ontology's description
            $advancedIndexation = FALSE;
          }
          elseif($this->in_array_r(Namespaces::$owl."Class", $description[Namespaces::$rdf."type"]))
          {
            $this->ws->ontology->updateClass($uri, $literalValues, $objectValues); 
          }
          elseif($this->in_array_r(Namespaces::$owl."DatatypeProperty", $description[Namespaces::$rdf."type"]) ||
                 $this->in_array_r(Namespaces::$owl."ObjectProperty", $description[Namespaces::$rdf."type"]) ||
                 $this->in_array_r(Namespaces::$owl."AnnotationProperty", $description[Namespaces::$rdf."type"]))
          {
            foreach($types as $type)
            {
              if(!is_array($objectValues[Namespaces::$rdf."type"]))
              {
                $objectValues[Namespaces::$rdf."type"] = array();
              }
              
              array_push($objectValues[Namespaces::$rdf."type"], $type);      
            }
          
            $this->ws->ontology->updateProperty($uri, $literalValues, $objectValues);   
          }
          else
          {
            $this->ws->ontology->updateNamedIndividual($uri, $types, $literalValues, $objectValues);   
          }
          
          // Call different API calls depending what we are manipulating
          if($advancedIndexation == TRUE)
          {          
            include_once("../../framework/arc2/ARC2.php");
            $rdfxmlParser = ARC2::getRDFParser();
            $rdfxmlSerializer = ARC2::getRDFXMLSerializer();
            
            $resourcesIndex = $rdfxmlParser->getSimpleIndex(0);
            
            // Index the entity to update
            $rdfxmlParser->parse($uri, $rdfxmlSerializer->getSerializedIndex(array($uri => $resourceIndex[$uri])));
            $rIndex = $rdfxmlParser->getSimpleIndex(0);
            $resourcesIndex = ARC2::getMergedIndex($resourcesIndex, $rIndex);                    
            
            // Check if the entity got punned
            $entities = $this->ws->ontology->_getEntities($uri);
            
            if(count($entities) > 1)
            {
              // The entity got punned.
              $isClass = FALSE;
              $isProperty = FALSE;
              $isNamedEntity = FALSE;
              
              
              foreach($entities as $entity)
              {
                if((boolean)java_values($entity->isOWLClass()))
                {
                  $isClass = TRUE;
                }              
                
                if((boolean)java_values($entity->isOWLDataProperty()) ||
                   (boolean)java_values($entity->isOWLObjectProperty()) ||
                   (boolean)java_values($entity->isOWLAnnotationProperty()))
                {
                  $isProperty = TRUE;
                }
                
                if((boolean)java_values($entity->isOWLNamedIndividual()))
                { 
                  $isNamedEntity = TRUE;
                }             
              }
              
              $queries = array();
              
              if($description[Namespaces::$rdf."type"][0]["value"] != Namespaces::$owl."Class" && $isClass)
              {
                array_push($queries, array("function" => "getClass", "params" => "uri=".$uri));
              }
              
              if($description[Namespaces::$rdf."type"][0]["value"] != Namespaces::$owl."DatatypeProperty" && 
                 $description[Namespaces::$rdf."type"][0]["value"] != Namespaces::$owl."ObjectProperty" &&
                 $description[Namespaces::$rdf."type"][0]["value"] != Namespaces::$owl."AnnotationProperty" &&
                 $isProperty)
              {
                array_push($queries, array("function" => "getProperty", "params" => "uri=".$uri));
              }
              
              if($description[Namespaces::$rdf."type"][0]["value"] != Namespaces::$owl."NamedIndividual" && $isNamedEntity)
              {
                array_push($queries, array("function" => "getNamedIndividual", "params" => "uri=".$uri));
              }            
              
              foreach($queries as $query)
              {
                // Get the class description of the current punned entity
                $ontologyRead = new OntologyRead($this->ws->ontologyUri, $query["function"], $query["params"],
                                                 $this->ws->registered_ip, $this->ws->requester_ip);

                // Since we are in pipeline mode, we have to set the owlapisession using the current one.
                // otherwise the java bridge will return an error
                $ontologyRead->setOwlApiSession($this->OwlApiSession);                                                    
                                  
                $ontologyRead->ws_conneg("application/rdf+xml", $_SERVER['HTTP_ACCEPT_CHARSET'], $_SERVER['HTTP_ACCEPT_ENCODING'],
                                       $_SERVER['HTTP_ACCEPT_LANGUAGE']);

                if($this->ws->reasoner)
                {
                  $ontologyRead->useReasoner(); 
                }  
                else
                {
                  $ontologyRead->stopUsingReasoner();
                }                                     
                                       
                $ontologyRead->process();
                
                if($ontologyRead->pipeline_getResponseHeaderStatus() != 200)
                {
                  $this->ws->conneg->setStatus($ontologyRead->pipeline_getResponseHeaderStatus());
                  $this->ws->conneg->setStatusMsg($ontologyRead->pipeline_getResponseHeaderStatusMsg());
                  $this->ws->conneg->setStatusMsgExt($ontologyRead->pipeline_getResponseHeaderStatusMsgExt());
                  $this->ws->conneg->setError($ontologyRead->pipeline_getError()->id, $ontologyRead->pipeline_getError()->webservice,
                    $ontologyRead->pipeline_getError()->name, $ontologyRead->pipeline_getError()->description,
                    $ontologyRead->pipeline_getError()->debugInfo, $ontologyRead->pipeline_getError()->level);

                  return;
                } 
                
                $entitySerialized = $ontologyRead->pipeline_serialize();
                
                $rdfxmlParser->parse($uri, $entitySerialized);
                $rIndex = $rdfxmlParser->getSimpleIndex(0);
                $resourcesIndex = ARC2::getMergedIndex($resourcesIndex, $rIndex);                
                
                unset($ontologyRead);            
              }
            }                   
            
            switch($description[Namespaces::$rdf."type"][0]["value"])
            {
              case Namespaces::$owl."Class":
              case Namespaces::$owl."DatatypeProperty":
              case Namespaces::$owl."ObjectProperty":
              case Namespaces::$owl."AnnotationProperty":
              case Namespaces::$owl."NamedIndividual":
              default:
              
                // We have to check if this entity to update is punned. If yes, we have to merge all the
                // punned descriptison together before updating them in structWSF (Virtuoso and Solr).
                // otherwise we will loose information in these other systems.
                
                // Once we start the ontology creation process, we have to make sure that even if the server
                // loose the connection with the user the process will still finish.
                ignore_user_abort(true);

                // However, maybe there is an issue with the server handling that file tht lead to some kind of infinite or near
                // infinite loop; so we have to limit the execution time of this procedure to 45 mins.
                set_time_limit(2700);                
                
                $serializedResource = $rdfxmlSerializer->getSerializedIndex($resourcesIndex);
                
                // Update the classes and properties into the Solr index
                $crudUpdate = new CrudUpdate($serializedResource, "application/rdf+xml", $this->ws->ontologyUri, 
                                             $this->ws->registered_ip, $this->ws->requester_ip);

                $crudUpdate->ws_conneg($_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_ACCEPT_CHARSET'], $_SERVER['HTTP_ACCEPT_ENCODING'],
                  $_SERVER['HTTP_ACCEPT_LANGUAGE']);

                $crudUpdate->process();
                
                if($crudUpdate->pipeline_getResponseHeaderStatus() != 200)
                {
                  $this->ws->conneg->setStatus($crudUpdate->pipeline_getResponseHeaderStatus());
                  $this->ws->conneg->setStatusMsg($crudUpdate->pipeline_getResponseHeaderStatusMsg());
                  $this->ws->conneg->setStatusMsgExt($crudUpdate->pipeline_getResponseHeaderStatusMsgExt());
                  $this->ws->conneg->setError($crudUpdate->pipeline_getError()->id, $crudUpdate->pipeline_getError()->webservice,
                    $crudUpdate->pipeline_getError()->name, $crudUpdate->pipeline_getError()->description,
                    $crudUpdate->pipeline_getError()->debugInfo, $crudUpdate->pipeline_getError()->level);

                  return;
                } 
                
                unset($crudUpdate);              
              
  /*            
                // Once we start the ontology creation process, we have to make sure that even if the server
                // loose the connection with the user the process will still finish.
                ignore_user_abort(true);

                // However, maybe there is an issue with the server handling that file tht lead to some kind of infinite or near
                // infinite loop; so we have to limit the execution time of this procedure to 45 mins.
                set_time_limit(2700);  
                
                $ser = ARC2::getTurtleSerializer();
                $serializedResource = $ser->getSerializedIndex(array($uri => $resourceIndex[$uri]));
                
                // Update the classes and properties into the Solr index
                $crudUpdate = new CrudUpdate($serializedResource, "application/rdf+n3", $this->ws->ontologyUri, 
                                             $this->ws->registered_ip, $this->ws->requester_ip);

                $crudUpdate->ws_conneg($_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_ACCEPT_CHARSET'], $_SERVER['HTTP_ACCEPT_ENCODING'],
                  $_SERVER['HTTP_ACCEPT_LANGUAGE']);

                $crudUpdate->process();
                
                if($crudUpdate->pipeline_getResponseHeaderStatus() != 200)
                {
                  $this->ws->conneg->setStatus($crudUpdate->pipeline_getResponseHeaderStatus());
                  $this->ws->conneg->setStatusMsg($crudUpdate->pipeline_getResponseHeaderStatusMsg());
                  $this->ws->conneg->setStatusMsgExt($crudUpdate->pipeline_getResponseHeaderStatusMsgExt());
                  $this->ws->conneg->setError($crudUpdate->pipeline_getError()->id, $crudUpdate->pipeline_getError()->webservice,
                    $crudUpdate->pipeline_getError()->name, $crudUpdate->pipeline_getError()->description,
                    $crudUpdate->pipeline_getError()->debugInfo, $crudUpdate->pipeline_getError()->level);

                  return;
                } 
                
                unset($crudUpdate);  
  */              
                            
              break;            
            }          
          }          
        }
        
        // Update the name of the file of the ontology to mark it as "changed"
        $this->ws->ontology->addOntologyAnnotation("http://purl.org/ontology/wsf#ontologyModified", "true");    
      }
    }    
    
    /**
    * Update the URI of an entity
    * 
    * @param mixed $oldUri
    * @param mixed $newUri
    * @param mixed $advancedIndexation
    *  
    * @author Frederick Giasson, Structured Dynamics LLC.
    */
    public function updateEntityUri($oldUri, $newUri, $advancedIndexation)
    { 
      $this->initiateOwlBridgeSession();

      $this->getOntologyReference();
      
      if($this->isValid())      
      {
        if($oldUri == "")
        {
          $this->ws->returnError(400, "Bad Request", "_202");
          return;              
        }          
        if($newUri == "")
        {
          $this->ws->returnError(400, "Bad Request", "_203");
          return;              
        }      
        
        $this->ws->ontology->updateEntityUri($oldUri, $newUri);
        
        if($advancedIndexation === TRUE)
        {   
          // Find the type of entity manipulated here
          $entity = $this->ws->ontology->_getEntity($newUri);
          
          $function = "";
          $params = "";
          
          if((boolean)java_values($entity->isOWLClass()))
          {
            $function = "getClass";
            $params = "uri=".$newUri;
          }
          elseif((boolean)java_values($entity->isOWLDataProperty()) ||
             (boolean)java_values($entity->isOWLObjectProperty()) ||
             (boolean)java_values($entity->isOWLAnnotationProperty()))
          {
            $function = "getProperty";
            $params = "uri=".$newUri;
          }
          elseif((boolean)java_values($entity->isNamedIndividual()))
          {
            $function = "getNamedIndividual";
            $params = "uri=".$newUri;
          }
          else
          {
            return;
          }
          
          // Get the description of the newly updated entity.
          $ontologyRead = new OntologyRead($this->ws->ontologyUri, $function, $params,
                                           $this->ws->registered_ip, $this->ws->requester_ip);

          // Since we are in pipeline mode, we have to set the owlapisession using the current one.
          // otherwise the java bridge will return an error
          $ontologyRead->setOwlApiSession($this->OwlApiSession);                                                    
                            
          $ontologyRead->ws_conneg("application/rdf+xml", $_SERVER['HTTP_ACCEPT_CHARSET'], $_SERVER['HTTP_ACCEPT_ENCODING'],
                                 $_SERVER['HTTP_ACCEPT_LANGUAGE']);

          if($this->ws->reasoner)
          {
            $ontologyRead->useReasoner(); 
          }  
          else
          {
            $ontologyRead->stopUsingReasoner();
          }                               
                                 
          $ontologyRead->process();
          
          if($ontologyRead->pipeline_getResponseHeaderStatus() != 200)
          {
            $this->ws->conneg->setStatus($ontologyRead->pipeline_getResponseHeaderStatus());
            $this->ws->conneg->setStatusMsg($ontologyRead->pipeline_getResponseHeaderStatusMsg());
            $this->ws->conneg->setStatusMsgExt($ontologyRead->pipeline_getResponseHeaderStatusMsgExt());
            $this->ws->conneg->setError($ontologyRead->pipeline_getError()->id, $ontologyRead->pipeline_getError()->webservice,
              $ontologyRead->pipeline_getError()->name, $ontologyRead->pipeline_getError()->description,
              $ontologyRead->pipeline_getError()->debugInfo, $ontologyRead->pipeline_getError()->level);

            return;
          } 
          
          $entitySerialized = $ontologyRead->pipeline_serialize();
          
          unset($ontologyRead);  

          // Delete the old entity in Solr        
          // Update the classes and properties into the Solr index
          $crudDelete = new CrudDelete($oldUri, $this->ws->ontologyUri, 
                                       $this->ws->registered_ip, $this->ws->requester_ip);

          $crudDelete->ws_conneg($_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_ACCEPT_CHARSET'], $_SERVER['HTTP_ACCEPT_ENCODING'],
            $_SERVER['HTTP_ACCEPT_LANGUAGE']);

          $crudDelete->process();
          
          if($crudDelete->pipeline_getResponseHeaderStatus() != 200)
          {
            $this->ws->conneg->setStatus($crudDelete->pipeline_getResponseHeaderStatus());
            $this->ws->conneg->setStatusMsg($crudDelete->pipeline_getResponseHeaderStatusMsg());
            $this->ws->conneg->setStatusMsgExt($crudDelete->pipeline_getResponseHeaderStatusMsgExt());
            $this->ws->conneg->setError($crudDelete->pipeline_getError()->id, $crudDelete->pipeline_getError()->webservice,
              $crudDelete->pipeline_getError()->name, $crudDelete->pipeline_getError()->description,
              $crudDelete->pipeline_getError()->debugInfo, $crudDelete->pipeline_getError()->level);

            return;
          } 
          
          unset($crudDelete);                
          
          // Add the new entity in Solr

          // Update the classes and properties into the Solr index
          $crudCreate = new CrudCreate($entitySerialized, "application/rdf+xml", "full", $this->ws->ontologyUri, 
                                       $this->ws->registered_ip, $this->ws->requester_ip);

          $crudCreate->ws_conneg($_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_ACCEPT_CHARSET'], $_SERVER['HTTP_ACCEPT_ENCODING'],
            $_SERVER['HTTP_ACCEPT_LANGUAGE']);

          $crudCreate->process();
          
          if($crudCreate->pipeline_getResponseHeaderStatus() != 200)
          {
            $this->ws->conneg->setStatus($crudCreate->pipeline_getResponseHeaderStatus());
            $this->ws->conneg->setStatusMsg($crudCreate->pipeline_getResponseHeaderStatusMsg());
            $this->ws->conneg->setStatusMsgExt($crudCreate->pipeline_getResponseHeaderStatusMsgExt());
            $this->ws->conneg->setError($crudCreate->pipeline_getError()->id, $crudCreate->pipeline_getError()->webservice,
              $crudCreate->pipeline_getError()->name, $crudCreate->pipeline_getError()->description,
              $crudCreate->pipeline_getError()->debugInfo, $crudCreate->pipeline_getError()->level);

            return;
          } 
          
          unset($crudCreate);                   
        }
            
        // Update the name of the file of the ontology to mark it as "changed"
        $this->ws->ontology->addOntologyAnnotation("http://purl.org/ontology/wsf#ontologyModified", "true");
      }
    }
    
    
    public function processInterface()
    {
    }
  }
?>
