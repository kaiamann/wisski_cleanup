<?php

namespace Drupal\wisski_cleanup\Plugin\rest\resource;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Request;
use Psr\Log\LoggerInterface;
use Drupal\node\Entity\Node;
use Drupal\rest\ModifiedResourceResponse;

use Drupal\wisski_salz\AdapterHelper;
use Drupal\Component\Serialization\Json;

use Drupal\wisski_unify\Utils;
use Drupal\wisski_unify\Queries;

/**
 * Provides a Demo Resource
 
 * @RestResource(
 *   id = "unify_cleanup",
 *   label = @Translation("Cleanup Resource"),
 *   uri_paths = {
 *     "canonical" = "/wisski/cleanup/rest/{path}",
 *     "create" = "/wisski/cleanup/rest"
 *   }
 * )
 */
class CleanupResource extends ResourceBase {

  /**
   * Responds to entity GET requests.
   * @return \Drupal\rest\ResourceResponse
   */
  public function get($path) {
    //$entity = Utils::getEntityForUri('http://objekte-im-netz.fau.de/orangerie/content/5d5ba247c08af');
    //return new ResourceResponse(Utils::extractWissKiData($entity));
    return new ResourceResponse("hi" . $path);
  }

  public function post($data){
    $decodedData = $data;

    #get query parameters
    $leafData = $decodedData['values'];
    $class = $decodedData['class'];

    $query = Queries::appellationInfo($class, $leafData);

    $result = Queries::executeQuery(Queries::appellationInfo($class, $leafData));
    
    $duplicateEntities = array();

    foreach($result as $res){
      $value = $res['leaf'];
      $uri = $res['o'];

      // $duplicateEntities[$value][] = Utils::normalizeEntity(Utils::getEntityForUri($uri));
      $entity = Utils::getEntityForUri($uri);
      $html = Utils::renderEntity($entity);
      // filter entities that cannot be rendered for now
      if(!$html)
        continue;
      $duplicateEntities[$value][$uri] = $html;
    }
    return new ModifiedResourceResponse($duplicateEntities);

  }
}

