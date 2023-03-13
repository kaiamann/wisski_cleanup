<?php
/**
 * @file
 *
 */

namespace Drupal\wisski_cleanup;

use Drupal\wisski_salz\AdapterHelper;
use Guzzle\Http\Exception\ClientErrorResponseException;

use DOMDocument;
use DOMElement;
use DOMText;


class Utils {


  // Networking

  /*
   * Performs a POST query to a URL.
   *
   * @param string $url
   *  The target URL
   * @param array $data
   *  The data to be sent
   * @param integer $timeout
   *  The timeout for the request
   *
   * @return array/int
   *  The response or status code in case of an error
   */
  public static function post($url, $data, $headers=array(), $timeout=120){

    // Make the request.
    $options = [
      'connect_timeout' => $timeout,
      'timeout' => $timeout,
      //'debug' => true,
      'headers' => $headers,      
      'json' => $data ,
      'verify'=>true,
    ];

    try {
      $client = \Drupal::httpClient();
      $request = $client->request('POST',$url,$options);
      $responseStatus = $request->getStatusCode();
      return $request->getBody()->getContents();
    } 
    catch (\GuzzleHttp\Exception\RequestException $e){
      if ($e->hasResponse()) {
        $response = $e->getResponse();
        $statusCode = $response->getStatusCode(); // HTTP status code;
        // $message = $response->getReasonPhrase(); // Response message;
        // $body = (string) $response->getBody(); // Body, normally it is JSON;
        // $decodedBody = json_decode((string) $response->getBody()); // Body as the decoded JSON;
        //var_dump($response->getHeaders()); // Headers array;
        //var_dump($response->hasHeader('Content-Type')); // Is the header presented?
        //var_dump($response->getHeader('Content-Type')[0]); // Concrete header value;
        //var_dump($e->getResponse());
        return $statusCode;
      }
      watchdog_exception('wisski_unify', $e);
    }
    catch (RequestException $e){
      // Log the error.
      watchdog_exception('wisski_unify', $e);
    }
  }


  // Random Helpers

  /*
   * Applies a function to all passed variables.
   * Basically array_map without array.
   *
   * @param $f 
   *  The funciton to be applied
   *
   * @param $args
   *  A list of variables
   */
  static function forall($f, &...$args){
    foreach($args as $k => &$v){
      $v = $f($v);
    }
  }

  /*
   * Sorts the given array by numbers occuring in values
   *
   * @param array &array
   *  The array to sort
   *
   * @return array
   *  The sorted array
   */ 
  public static function numsort($array){
    $f = function($s1, $s2){
      $n1 = preg_replace('/\D/', '', $s1);
      $n2 = preg_replace('/\D/', '', $s2);
      if($n1 > $n2)
        return 1;
      if($n1 < $n2)
        return-1;
      else 
        return 0;
    };
    uasort($array, $f);
    return $array;
  }


  /*
   * Finds compares an array to another array and
   * searches for matches/conflicts.
   *
   * @param $a1
   *  The array to be compared
   * @param $a2
   *  The array that $a1 should to be compared to 
   *
   * @return array
   *  An array of the same structure as $a1, but having the
   *  values replaced by numbers representing an comparison status:
   *  0 => match
   *  1 => conflict
   *  2 => not present in $a2
   *
   */
  static function compare($a1, $a2){
    $p1 = array();

    foreach($a1 as $key => $value){
      if(array_key_exists($key, $a2)){
        if(is_array($a1[$key]) && is_array($a2[$key])){
          $p1[$key] = self::compare($a1[$key], $a2[$key]);
        }
        else if(!is_array($a1[$key]) && !is_array($a2[$key])){
          $p1[$key] = $a1[$key] == $a2[$key] ? 0 : 1;
        }
        else if(is_array($a1[$key])){
          foreach($a1[$key] as $k => $v){
            $p1[$key][$k] = $v == $a2[$key] ? 0 : 1;
          }
        }
      }
      else {
        if(is_array($a1[$key])){
          $p1[$key] = self::compare($a1[$key], []);
        }
        else{
          $p1[$key] = 2;
        }
      }
    }

    return $p1;
  }



  // Entity Serialization/Normalization

  /**
   * Converts an entity to an array
   *
   * @param Entity $entity
   *    The entity to be serialized
   *
   * @return array
   *    The array representation of the entity
   */
  public static function normalizeEntity($entity){
    $serializer = \Drupal::service('serializer');
    return $serializer->normalize($entity);
  }


  /*
   * Converts an array to an Entity
   *
   * @param array $array
   *  The array to be converted
   * @param string $entityType
   *  The desired entity class
   */
  static function denormalizeEntity($array, $entityType){
    $serializer = \Drupal::service('serializer');
    return $serializer->denormalize($array, $entityType);
  } 

  /**
   * Deserializes a JSON string into an Entity
   *
   * @param string $json
   *  The JSON string representing the Entity
   * @param string $class
   *  The class of the Entity, relevant here:
   *  - Drupal\wisski_pathbuilder\Entity\WisskiPathEntity
   *  - Drupal\wisski_core\Entity\WisskiEntity
   *
   * @return Entity
   *  The deserialized Entity
   */
  static function deserializeEntity($json, $entityType){
    $serializer = \Drupal::service('serializer');
    return $serializer->deserialize($json, $entityType ,'json');
  }

  /**
   * Converts an entity to JSON
   *
   * @param Entity $entity
   *    The entity to be serialized
   *
   * @return string
   *    The JSON string
   */
  static function serializeEntity($entity){
    $serializer = \Drupal::service('serializer');
    return $serializer->serialize($entity, 'json', ['plugin_id' => 'entity']);
  }


  // Drupal specific stuff

  /*
   * Returns the Entity for a given Entity URI
   *
   * @param string $uri
   *    The URI
   * @param string $entity_type
   *    The expected type of the entity
   *
   * @return WissKiEntity(?)
   */
  static function getEntityForUri($uri, $entity_type = 'wisski_individual'){
    // make sure uri is unescaped for drupal
    $escapedUri = str_replace(['<','>'] ,'', $uri);
    $entity_id = AdapterHelper::getDrupalIdForUri($escapedUri);
    return \Drupal::entityTypeManager()->getStorage($entity_type)->load($entity_id);
  }

  /*
   * Gets the wisski credentials for an external wisski
   * from /wisski_unify/config/install/wisski_unify.credentials.yml
   *
   * @param string $host
   *  The hostname of the desired wisski
   *
   * @return array
   *  An array containaing the credentials,
   *  or null if none were found.
   */
  public static function getCredentials($host){
    $config = \Drupal::config('wisski_unify.credentials');
    $rawData = $config->getRawData();
    foreach($rawData as $wisski){
      if(array_key_exists('host', $wisski) && 
          array_key_exists('user', $wisski) &&
          array_key_exists('password', $wisski)
        ){
        return array( 
            $wisski['user'], 
            $wisski['password'] 
            );
      }
    }
    return null;
  }


  // Entity Rendering/HTML conversion

  /*
   * Produces the render data for a given entity,
   *
   * @param Entity $entity
   *  The entity to be rendered
   * @param string $entityType
   *  The type of the entity
   * @param string $viewMode
   *  The view mode
   *
   * @return array
   *  The render array
   */
  static function preRenderEntity($entity, $entityType = 'wisski_individual', $viewMode = 'full'){
    $viewBuilder = \Drupal::entityTypeManager()->getViewBuilder($entityType);
    $preRender = $viewBuilder->view($entity, $viewMode);
    return $preRender;
  }

  /*
   * Converts an Entity into renderable HTML
   * using Drupals built-in render service.
   *
   * @param Entity $entity
   *  The entity to be rendered
   * @param string $entityType
   *  The type of the entity
   * @param string $viewMode
   *  The view mode
   *
   * @return string
   *  The html render data 
   */
  static function renderEntity($entity, $entityType = 'wisski_individual', $viewMode = 'full'){
    $viewBuilder = \Drupal::entityTypeManager()->getViewBuilder($entityType);
    $preRender = $viewBuilder->view($entity, $viewMode);
    $renderOutput = render($preRender);
    return $renderOutput;
  }

  /*
   * Generates HTML for a given normalized WissKi entity
   * and its matching/conflicting data
   *
   * @param array $normalizedEntity
   *  The entity data to be converted
   *
   * @param array $matchingData
   *  The matching/conflicting metadata.
   *  Has to have the same structure as $normalizedEntity,
   *  just with the values replaced by numbers in [0, 1, 2].
   *  0 === match => green   
   *  1 === conflict => red
   *  2 === neutral => normal
   *
   * @param number $level
   *  The starting level for headers <h{$level}>
   *
   * @return string
   *  The html string
   */
  static function generateHtml($normalizedEntity, $matchingData, $level=5){
    $classes = ['match', 'conflict', 'neutral'];
    $html = "";
    foreach($normalizedEntity as $key => $value){
      // label
      if(!is_numeric($key))
        $html .= "<h{$level}>{$key}</h{$level}>";
      // value list
      if(is_array($value)){
        $html .= self::generateHtml($value, $matchingData[$key], $level+1);
      }
      else {
        $class = $classes[$matchingData[$key]];
        $html .= "<div class=\"{$class}\">{$value}</div>";
      }
    }

    return $html;
  }




  /*
   * Gets the route path for a route
   *
   * @param string $routeName
   *  The Drupal name of the route
   */
  static function getRoutePath($routeName){
    $route_provider = \Drupal::service('router.route_provider');
    $route = $route_provider->getRouteByName($routeName);
    // $controller = $route->getPath('_controller');
    return $route->getPath();
  }


  // HTML conversion stuff

  /*
   * Converts a html string to an array
   * @param string $html
   *  The html string
   *
   * @return array
   *  The corresponding data
   *
   * @see DOMToArray
   */
  static function HTMLToArray($html){
    // remove unneccessary whitespaces between the tags
    $html = preg_replace('/\>\s+\</m', '><', $html);

    $dom = new DOMDocument();
    $dom->loadHTML('<meta charset="utf-8">' . $html);
    $body = $dom->getElementsByTagName('body')[0];
    $res = array();
    foreach($body->childNodes as $child){
      if($child instanceof DOMElement)
        $res = array_merge($res, self::DOMToArray($child));
    }   
    return $res;
  }

  /*
   * Converts a DOMElement to array.
   *
   * @param DOMElement
   *  The DOMElement to be converted.
   *  Corresponds to a 
   *  <div class="field field-name-DrupalFieldID"> ... </div>
   *  of a HTML rendered (WissKi)Entity
   *
   * @return arary
   *  The serialized DOMElement data
   */
  private static function DOMToArray(DOMElement $element){
    $tag = $element->tagName;
    $class = $element->getAttribute('class');

    // field
    if(str_starts_with($class, "field field--name")){
      $label = null;
      $valueNode = null;
      // find label and field__item/s nodes
      foreach($element->childNodes as $child){
        $class = $child->getAttribute("class"); 
        if($class == "field__item" || $class == "field__items"){
          $valueNode = $child;
        }
        if($class == "field__label"){
          $label = $child->nodeValue;
        }
      }
      // handle field__item
      if($valueNode->getAttribute("class") == "field__item"){
        $value = self::handleFieldItem($valueNode);
      }
      // handle field__items
      if($valueNode->getAttribute("class") == "field__items"){
        $value = array();
        foreach($valueNode->childNodes as $child){
          $value[] = self::handleFieldItem($child);
        } 
      } 
    }
    if($label)
      return [$label => $value];
    else
      return [ $value ];

  }

  /*
   * Handles a field-item corresponding to a
   * <div class="field__item">value</div>
   *
   * @param DOMElement $fieldItem
   *
   * @return value
   *  The value of the field__item
   */
  private static function handleFieldItem(DOMElement $fieldItem){
    $value = array();
    // plain text
    if($fieldItem->childElementCount == 0){
      return $fieldItem->nodeValue;
    }
    else{
      $isText = false;
      foreach($fieldItem->childNodes as $child){
        // stop if there are Text elements
        if($child instanceof DOMText){
          $isText = true;
          break;
        }
        $tag = $child->tagName;
        $class = $child->getAttribute("class");
        // field
        if(str_starts_with($class, "field field--name")){
          $value = array_merge($value, self::DOMToArray($child));
        }
        //link
        if($tag == 'a'){
          $href = $child->getAttribute('href');
          if(str_starts_with($href, "/wisski/navigate/")){
            $host = \Drupal::request()->getHost();
            $href = "http://" . $host . $href;
          }
          $value = "<a href=\"{$href}\">{$child->nodeValue}</a>";
        }
        // TODO: add image handling if needed
      }
      // HTML markup
      if($isText){
        $value = $fieldItem->nodeValue;
      }
    }
    return $value;
  }


  /*
   * Extracts the data from a wisski Enity
   *
   * Deprecated Do not use
   */
  static function extractWissKiData($entity){
    $defaultFieldNames = [ 
      'eid',
      'langcode',
      'bundle',
      'published',
      'wisski_uri',
      'label',
      'preview_image',
      'default_langcode'
    ];  
    $normalizedEntity = array();
    foreach($entity as $fieldName => $fieldItemList){
      if(in_array($fieldName, $defaultFieldNames))
        continue;
      foreach($fieldItemList as $weight => $fieldItem){
        $label = $fieldItem->getFieldDefinition()->getLabel();
        if($label instanceof \Drupal\Core\StringTranslation\TranslatableMarkup){
          $label = $label->render();
        }   

        // get the raw values from the field item
        $fieldItemValue = $fieldItem->getValue();
        foreach($fieldItemValue as $key => $value){
          $res = null;
          if($key == 'target_id'){
            $linkedEntity = \Drupal::entityTypeManager()->getStorage('wisski_individual')->load($value);
            //if($label == "Copy of"){
            $res = $linkedEntity->toLink()->toString()->getGeneratedLink();
            //}
            //This creates infinite loops because of circles 
            //in the data when copy_of links are involved
            //else {
            //  $res = self::extractWissKiData($entity);
            //}
          }   
          if($key == 'value'){
            $res = $value;
          }
          if($key == 'uri'){
            $res = '<a href="' . $value . '">' . $value . '</a>';
          }
          if($res){
            $normalizedEntity[$label][] = $res;
          }
        }
      }
    }
    return $normalizedEntity;
  }

}
