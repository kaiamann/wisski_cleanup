<?php

namespace Drupal\wisski_cleanup\Form;

use Drupal\wisski_salz\Entity\Adapter;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;

use Drupal\wisski_merge\Merger;
use Drupal\wisski_salz\AdapterHelper;
use Drupal\Component\Serialization\Json;

# import these for namespace resolution
use Drupal\wisski_core\Entity\WisskiEntity;
use Drupal\wisski_pathbuilder\Entity\WisskiPathEntity;

use Drupal\wisski_cleanup\Queries;
use Drupal\wisski_cleanup\Utils;
use Drupal\wisski_cleanup\QueryResultFormat;
use Drupal\wisski_salz\Controller\wisski_salzTriplesTabController;


class CleanupForm extends FormBase {


  public function getFormId(){
    return self::class;
  }

  public function insertTestData(){
    $query = "PREFIX test: <http://test-ontology.com/>
INSERT DATA
      {
      GRAPH <http://testt-data.com/> {
        <http://testt-data.com/book1> a <http://erlangen-crm.org/170309/E1_CRM_Entity> .
        <http://testt-data.com/book2> a <http://erlangen-crm.org/170309/E1_CRM_Entity> .
        <http://testt-data.com/book3> a <http://erlangen-crm.org/170309/E1_CRM_Entity> .
        <http://testt-data.com/book4> a <http://erlangen-crm.org/170309/E1_CRM_Entity> .
        <http://testt-data.com/book5> a <http://erlangen-crm.org/170309/E1_CRM_Entity> .
        <http://testt-data.com/book6> a <http://erlangen-crm.org/170309/E1_CRM_Entity> .
        
        <http://testt-data.com/book1> test::P01_copy_of <http://testt-data.com/book2> .
        <http://testt-data.com/book2> test::P01_copy_of <http://testt-data.com/book3> .
        
        <http://testt-data.com/book1> <http://erlangen-crm.org/170309/P3_has_note>\"Book 1\" .
        <http://testt-data.com/book2> <http://erlangen-crm.org/170309/P3_has_note> \"Book 2\" .
        <http://testt-data.com/book3> <http://erlangen-crm.org/170309/P3_has_note> \"Book 3\" .
        <http://testt-data.com/book4> <http://erlangen-crm.org/170309/P3_has_note> \"Book 4\" .
        <http://testt-data.com/book5> <http://erlangen-crm.org/170309/P3_has_note> \"Book 5\" .
        <http://testt-data.com/book6> <http://erlangen-crm.org/170309/P3_has_note> \"Book 6\" .
                                 
          }
      }";

    Queries::executeUpdate(array('query' => $query));
  }

  public function buildForm(array $form, FormStateInterface $form_state){

    $query = array(
        'query' => "Select distinct ?g where { 
        graph ?g { ?s ?p ?o . } . 
        filter(not exists {?g a <http://www.w3.org/2002/07/owl#Ontology> }) .
        filter( ! regex(str(?g), \"baseFields\" ) && ! regex(str(?g), \"originatesFrom\" ) ) .
        }",
        'variables' => ['g']
        );

    $form['insert_test_data'] = array(
        '#type' => 'submit',
        '#value' => 'Insert http://ttest-data.com',
        '#submit' => array('::insertTestData')
        );

    $graphs = array();
    $graphs = Queries::executeQuery($query, QueryResultFormat::COLUMN)['g'];
    $graphs = array_combine($graphs, $graphs);

    $form['select_graph'] = array(
        '#type' => 'select',
        '#title' => 'Select the Graph to check for unused data',
        '#default_value' => '0',
        '#options' => array_merge(['0' => 'Please select'], $graphs),
        '#ajax' => array(
          'callback' => 'Drupal\wisski_cleanup\Form\CleanupForm::ajaxStores',
          'wrapper' => 'stores_div',
          'event' => 'change',
          //'effect' => 'slide',
          ),

        );

    // ajax stores
    $form['stores'] = array(
        '#type' => 'markup',
        // The prefix/suffix provide the div that we're replacing, named by
        // #ajax['wrapper'] below.
        '#prefix' => '<div id="stores_div">',
        '#suffix' => '</div>',
        '#value' => "",
        );


    $unusedNodes = 0;
    if($form_state->getValue("select_graph")){
      $graph = $form_state->getValue("select_graph");

      $res = Queries::executeQuery(Queries::isolatedNodes($graph));


      $options = array();
      $uris = array();


      // this is the WissKiStorage
      // sadly loadMultiple only returns an empty array
      // $storage = \Drupal::service('entity_type.manager')->getStorage('wisski_individual')->loadMultiple();



      foreach($res as $row){

        // unescape for display
        $uri = str_replace(['<','>'], "", $row['uri']);
        $class = str_replace(['<','>'], "", $row['class']);
        $notes = $row['notes'];

        $connectedNodes = explode("|", $notes);
        $unusedNodes += count($connectedNodes);
        $convert2Link = function($uri){

          // for accessing the incoming/outgoing triples page...
          // for some reason we need an existing wisski_individual entityID for this
          // This should theoretically work, however the WissKI storage does not support loading all WissKI entities
          // by passing nothing to loadMultiple() so this does not work...
          // $eids = \Drupal::entityTypeManager()->getStorage('wisski_individual')->loadMultiple();
          // $entityId = current($eids);
          // TODO: FIX THIS HACK
          // pray to god wisski_entity with this eid exists
          $entityId = 386;
          $url = Url::fromRoute('wisski_salz.wisski_individual.triples', array('wisski_individual' => $entityId, 'target_uri' => $uri ) );
          $link = Link::fromTextAndUrl($uri, $url)->toRenderable();
          // wrap in item for nice formatting
          $item = array(
            '#type' => 'item'
          );
          $item[] = $link;
          return $item;
        };


        $connectedNodes = array_map($convert2Link, $connectedNodes);

        $classLink = $convert2Link($class);
        $uriLink = $convert2Link($uri);

        $uris[] = $row['uri'];
        $options[$uri] = [ 
          'class' => array("data" => $classLink),
          'uri' => array("data" => $uriLink),
          'note' => array(
              "data" => $connectedNodes,
              // array(
              //   '#type' => 'markup',
              //   '#markup' => $notes
              //   )
              ) 
        ]; 

      }


      $form['stores']['count'] = array(
          '#type' => 'markup',
          '#markup' => "Found " . count($res) . " unused top level nodes" // . \n Found at least" . $unusedNodes . " unused nodes"
          );

      $form['stores']['uris'] = array(
          '#type' => 'value',
          '#value' => $uris
          );

      $form['stores']['table'] = array(
          '#type' => 'tableselect',
          '#header' => array(
            "uri" => t("Uri"),
            "class" => t("Class"),
            "note" => t("Linked Uris"),
            ),
          '#options' => $options,
          );

      $form['stores']['submit'] = array(
          '#type' => 'submit',
          '#value' => t('Delete'),
          );
      $form['stores']['notice'] = array(
          '#type' => 'plain_text',
          '#plain_text' => 'Disclaimer: deleting a node will also delete all its child nodes'
          );



    }

    //$controller->forward("http://objekte-im-netz.fau.de/orangerie/content/5d5ba247c08aif");

    return $form;

  }

  public static function deleteNodes($uris){
    $columns = Queries::executeQuery(Queries::linkedNodes($uris), QueryResultFormat::COLUMN);
    if(!empty($columns)){
      self::deleteNodes($columns['o']);
    }
    Queries::executeUpdate(Queries::deleteNodes($uris));
  }

  /*
   * Return the 'stores' field of the form.
   * Used for getting the data to be displayed on AJAX callback.
   */
  public static function ajaxStores(array $form, FormStateInterface $form_state) {
    return $form['stores'];
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    #   dpm('hello1');
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    // get selected uris
    $selection = array_values($form_state->getValues('values')['table']);
    $f = function($element) { return !is_numeric($element); };
    $uris = array_filter($selection, $f);


    self::deleteNodes($uris);

    $form_state->setRebuild(TRUE);
    $form_state->setValue('table', array());
    $input = $form_state->getUserInput();
    $form_state->setUserInput($input);
    #    $options =
    #    dpm("submit called!");





  }
}
