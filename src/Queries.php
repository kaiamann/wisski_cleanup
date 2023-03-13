<?php

namespace Drupal\wisski_cleanup;

use Drupal\wisski_unify\Utils;
use Drupal\wisski_unify\Config;

// Constants for query data
const URI = 'uri';
const VALUE = 'value';

const QUERY = 'query';
const VARIABLES = 'variables';

/*
 * TODO: turn this into a enum with PHP 8.1
 *
 * Used to define the format in which
 * a query result should be represented.
 *
 * ROW: list data by row
 * COLUMN: list data by columns
 */
abstract class QueryResultFormat {
  const ROW = 'row';
  const COLUMN = 'column';
}


class Queries {




  /*
   * Loads the ontology from the config and returns it
   *
   * @return true if successful false otherwise
   */
  public static function loadOntology(){
    $config = \Drupal::config('wisski_unify.ontology');
    $ontology = $config->getRawData();
    unset($ontology['_core']);
    return $ontology;
  }

  // Query string construction

  /*
   *
   * @param string $superlcass
   *  The CRM superlcass to search under.
   *
   * @return array
   *  The query info
   */
  static function appellationInfo($superclass, $filters=array()){
    $ontology = self::loadOntology();

    # build query
    $query = "SELECT ?o ?leaf WHERE { \n";
    $query .= "?is_identified_by " . $ontology['subPropertyOf'] . "* " . $ontology['is_identified_by'] . " .\n";
    $query .= "?appellation " . $ontology['subClassOf'] . "* " . $ontology['appellation'] . " .\n";
    $query .= "?oclass " . $ontology['subClassOf'] . "* " . $superclass . " .\n";
    $query .= "?datatypeProperty a " . $ontology['datatypeProperty'] . " .\n";
    $query .= "?o ?is_identified_by ?a .\n";
    $query .= "?o a ?oclass .\n";
    $query .= "?a a ?appellation .\n";
    $query .= "?a ?datatypeProperty ?leaf .\n";

    if(!empty($filters)){
      $query .= self::buildFilters('?leaf', $filters);
    }
    $query .= "\n}";

    return array(
        QUERY => $query,
        VARIABLES => array(
          'o',
          'leaf')
        );
  }

  /*
   * Query for getting WissKiInfo
   *
   * @return array
   *  The query info
   */
  static function wisskiInfo(){
    $ontology = self::loadOntology();

    $query = "SELECT ?name ?uri ?url ?prefix  WHERE { ";
    $query .= "?uri a " . $ontology['wisski'] . " . ";
    $query .= "?uri " . $ontology['has_note'] . " ?name . ";
    $query .= "?uri " . $ontology['has_url'] . " ?url . ";
    $query .= "?uri " . $ontology['has_uri_prefix'] . " ?prefix . ";
    $query .= "}"; 

    return array(
        QUERY => $query,
        VARIABLES => array(
          'name',
          'uri',
          'url',
          'prefix'
          )
        );
  }


  /*
   * Query for getting all CRM classes
   *
   * @return array 
   *  The query info 
   */
  static function crmClasses(){
    $ontology = self::loadOntology();
    $query = "SELECT ?class WHERE { GRAPH " . $ontology['crm'] . " { ?class a " . $ontology['class'] . " .} }";
    return array(
        QUERY => $query,
        VARIABLES => array(
          'class'
          )
        );
  }


  static function insertLinks($selectedUris){
    $ontology = self::loadOntology();
    $f = function ($conflict) use($ontology) { return $conflict['local'] . $ontology['copy_of'] . $conflict['external'] . " . "; };
    $triples = array_map($f, $selectedUris);

    $query = "INSERT DATA { GRAPH " . $ontology['graph'] . " {\n";
    $query .= implode('', $triples);
    $query .= "} }";
    return array(
        QUERY => $query
        );
  }

  static function linkedEntities(string $baseUri=null){
    $ontology = self::loadOntology();
    $query = "SELECT ?s ?o WHERE {";
    $query .= "GRAPH {$ontology['graph']} { ?s {$ontology['copy_of']} ?o . } .\n";
    if($baseUri){
      $query .= "FILTER (strStarts(str(?o), \"{$baseUri}\")) .\n";
    }
    $query .= "}";
    return array(
        QUERY => $query,
        VARIABLES => array(
          "o",
          "s"
          )
        );
  }


  static function linkedNodes($uris){
    $query = "select ?o where { {";
    $statements = array();
    foreach($uris as $uri){
      $escaped = self::escapeUri($uri);
      $statements[] = "{$escaped} !a ?o .\n";
    }
    $query .= implode("} union {", $statements );
    $query .= "}";
    $query .= "filter(isUri(?o)) .\n";
    $query .= "}";


    return array(
        QUERY => $query,
        VARIABLES => array(
          "o",
          ));
  }


  /*
   * Query for deleting all triples that are outgoing 
   * from the given uris
   *
   * @param array $uris
   *  An array of uris
   *
   * @return
   *  Thq query info
   */
  static function deleteNodes($uris){
    $query = ""; 
    foreach($uris as $uri){
      $escaped = self::escapeUri($uri);
      $query .= "Delete where { {$escaped} ?p ?o . };\n";
    }

    return array(
        QUERY => $query,
        );
  }


  /*
   * Query for deleting copy_of links.
   *
   * @param string $baseUri
   *  The unescaped base URI of the external WissKi URI 
   *  to which the copy_of link should be deleted.
   *
   * @return array
   *  The query info
   */
  static function deleteCopyOf(string $baseUri=null){
    $ontology = self::loadOntology();
    $query = "DELETE {?s " . $ontology['copy_of'] . " ?o } WHERE ";
    $query .= "{ GRAPH " . $ontology['graph'] . " { ?s " . $ontology['copy_of'] . " ?o . } .\n";
    if($baseUri){
      $query .= "FILTER (strStarts(str(?o), \"" . $baseUri . "\")) .\n";
    }
    $query .= "}";

    return array(
        QUERY => $query,
        );
  }

  /*
   * Query for getting the number of items that will be deleted
   *
   * @param string $baseUri
   *  The unescaped base URI of the external WissKi URI 
   *  to which the copy_of link should be deleted.
   *
   * @return array
   *  The query info
   */
  static function numLinks(string $baseUri=null){
    $ontology = self::loadOntology();
    $query = "SELECT (COUNT(*) as ?cnt) WHERE {";
    $query .= "GRAPH " . $ontology['graph'] . " { ?s " . $ontology['copy_of'] . " ?o . } .\n";
    if($baseUri){
      $query .= "FILTER (strStarts(str(?o), \"" . $baseUri . "\")) .\n";
    }
    $query .= "}";

    return array(
        QUERY => $query,
        VARIABLES => array(
          'cnt'
          )
        );
  }


  static function isolatedNodes($graphUri){
    $query = "select distinct ?uri ?class (GROUP_CONCAT(?nuri; separator='|') as ?notes) where {
      graph {$graphUri} { ?uri a ?class . } .
        ?uri !a ?n .
        filter(not exists { ?o ?_ ?uri .}) .
        BIND(if(isiri(?n), ?n, '') as ?nuri)
    } group by ?uri ?class order by ?class";

    return array(
        QUERY => $query,
        VARIABLES => array(
          'uri',
          'class',
          'notes',
          )
        );
  }

  // Random Helpers

  /*
   * Builds a filter segment for a SPARQL query.
   *
   * @param string $varName
   *  The name of the variable that should be filtered
   * @param array filterItems
   *  The values that should be filtered against
   * @param string $op
   *  The operator that should be used for comparing
   * @param string/array
   *  The escape character/s for escaping the values
   *
   * @return string
   *  A SPARQL query filter segment
   */
  private static function buildFilters(string $varName, array $filterItems, string $op = '=', $esc='\''){
    if(!is_array($esc)){
      $esc = array($esc, $esc);
    }
    $filterString = "FILTER(";
    $f = function($i) use($varName, $op, $esc){ return "\n" . $varName . " " . $op . " " . $esc[0] . $i . $esc[1] . " "; };
    $filters = array_map($f, $filterItems);
    $filterString .= implode('||', $filters) . ") . ";
    return $filterString;
  }

  /*
   * Escapes a Uri.
   * Does not double escape if Uri is already escaped.
   *
   * @param string $uri
   *  The uri to be escaped
   *
   * @return string
   *  The escaped Uri
   */
  static function escapeUri($uri){
    return '<' . str_replace(['<','>'],'', $uri) . '>';
  }


  /*
   * Executes a query for all available wisski_salz adapters.
   *
   * @param array $queryData
   *  Array containing the query and information
   *  about the variables used in the query.
   *  e.g.
   *  [
   *    QUERY => "Query Sting"
   *    'variableName' => variable type (either VALUE or URI)
   *  ]
   *
   * @param string $format
   *  The format in which the data should be returned.
   *
   * @return array
   *  The parsed result of the query.
   *  One entry corresponds to one query result row.
   */
  public static function executeQuery(array $queryData, $format = QueryResultFormat::ROW){
    $results = array();

    // iterate over available adapters
    $adapters = \Drupal::entityTypeManager()->getStorage('wisski_salz_adapter')->loadMultiple();
    foreach($adapters as $adapter){
      $engine = $adapter->getEngine();
      if (!($engine instanceof \Drupal\wisski_adapter_sparql11_pb\Plugin\wisski_salz\Engine\Sparql11EngineWithPB))
        continue;

      $queryResult = $engine->directQuery($queryData[QUERY]);

      if(!array_key_exists(VARIABLES, $queryData))
        continue;

      if($format == QueryResultFormat::ROW){
        // iterate over each row
        foreach($queryResult as $res){
          $result = array();
          // iterate over variables of the row
          foreach($queryData[VARIABLES] as $variable){
            if(method_exists($res->$variable, 'getUri')){
              $result[$variable] = self::escapeUri($res->$variable->getUri());

            }
            else if (method_exists($res->$variable, 'getValue')){
              $result[$variable] = $res->$variable->getValue();
            }
            else {
              $results[$variable][] = "No Value";
            }

          }
          $results[] = $result;
        }
      }
      else if ($format == QueryResultFormat::COLUMN){
        // iterate over each row
        foreach($queryResult as $res){
          // iterate over variables of the row
          foreach($queryData[VARIABLES] as $variable){
            if(method_exists($res->$variable, 'getUri')){
              $results[$variable][] = self::escapeUri($res->$variable->getUri());
            }
            else if (method_exists($res->$variable, 'getValue')){
              $results[$variable][] = $res->$variable->getValue();
            }
            else {
              $results[$variable][] = "No Value";
            }
          }
        }
      }
    }

    return $results;
  }

  /*
   * Executes an update Query.
   *
   * @param array $queryData
   *  Array containing the query and information
   *  about the variables used in the query.
   *  e.g.
   *  [
   *    QUERY => "Query Sting"
   *    'variableName' => variable type (either VALUE or URI)
   *  ]
   *
   * @return array 
   *  an array of EasyRdf\Http\Response
   */
  static function executeUpdate(array $queryData){
    // iterate over available adapters
    $adapters = \Drupal::entityTypeManager()->getStorage('wisski_salz_adapter')->loadMultiple();
    $res = array();
    foreach($adapters as $adapter){
      $engine = $adapter->getEngine();
      if (!($engine instanceof \Drupal\wisski_adapter_sparql11_pb\Plugin\wisski_salz\Engine\Sparql11EngineWithPB))
        continue;

      $res[] = $engine->directUpdate($queryData[QUERY]);
    }
    return $res;
  }

}

