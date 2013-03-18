<?php
/**
 * This file is part of the {@link http://aksw.org/Projects/Xodx Xodx} project.
 *
 * @license http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */

// include password hash functions for 5.3.7 <= PHP < 5.5
require_once('password_compat/lib/password.php');

/**
 * This class manages instances of Xodx_User.
 * this includes:
 *  - subscribing to a feed
 *  - getting notifications
 */
class Xodx_UserController extends Xodx_ResourceController
{
    /**
     * A registry of already loaded Xodx_User objects
     */
    private $_users = array();

    /**
     * This action gets the notifications for the specified user
     * @param user (get) the uri of the user who wants to get its notifications
     * @return json representation of the Xodx_Notification objects
     */
    public function getNotificationsAction ($template)
    {
        $bootstrap = $this->_app->getBootstrap();
        $request = $bootstrap->getResource('request');

        $userUri = $request->getValue('user', 'get');

        if ($userUri === null) {
            $userUri = $this->getUser()->getUri();
        }

        $notifications = $this->getNotifications($userUri);

        $template->disableLayout();

        $notificationsJson = json_encode($notifications);
        $template->setRawContent($notificationsJson);

        return $template;
    }

    /**
     *
     * Enter description here ...
     * @param unknown_type $subscriberUri
     * @param unknown_type $feedUri
     */
    public function subscribeToResource ($subscriberUri, $resourceUri, $feedUri = null)
    {

        $model = $this->_app->getBootstrap()->getResource('model');

        if ($feedUri === null) {
            $feedUri = $this->getActivityFeedUri($resourceUri);
        }

        $feedObject = array(
            'type' => 'uri',
            'value' => $feedUri
        );

        $nsDssn = 'http://purl.org/net/dssn/';
        $model->addStatement($resourceUri, $nsDssn . 'activityFeed', $feedObject);

        $this->_subscribeToFeed($subscriberUri, $feedUri);

    }

    /**
     * This method subscribes a user to a feed
     * @param $userUri the uri of the user who wants to be subscribed
     * @param $feedUri the uri of the feed where she wants to subscribe
     */
    private function _subscribeToFeed ($subscriberUri, $feedUri)
    {
        $bootstrap = $this->_app->getBootstrap();
        $logger = $bootstrap->getResource('logger');
        $resourceController = $this->_app->getController('Xodx_ResourceController');

        $nsFoaf = 'http://xmlns.com/foaf/0.1/';
        $feed = DSSN_Activity_Feed_Factory::newFromUrl($feedUri);
        $type = $resourceController->getType($subscriberUri);

        if ($type === $nsFoaf . 'Person') {
            $subscriberUri = $this->getUserUri($subscriberUri);
        }

        $logger->info('subscribeToFeed: user: ' . $subscriberUri . ', feed: ' . $feedUri);

        if (!$this->_isSubscribed($subscriberUri, $feedUri)) {
            $pushController = $this->_app->getController('Xodx_PushController');
            if ($pushController->subscribe($feedUri)) {

                $store    = $bootstrap->getResource('store');
                $model    = $bootstrap->getResource('model');
                $graphUri = $model->getModelIri();

                $nsDssn = 'http://purl.org/net/dssn/';
                $nsRdf = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

                $subUri = $this->_app->getBaseUri() . '&c=ressource&id=' . md5(rand());
                $cbUri  = $this->_app->getBaseUri() . '?c=push&a=callback';

                $subscription = array(
                $subUri => array(
                    $nsRdf . 'type' => array(
                        array(
                            'type' => 'uri',
                            'value' => $nsDssn . 'Subscription'
                        )
                    ),
                    $nsDssn . 'subscriptionCallback' => array(
                        array(
                            'type' => 'uri',
                            'value' => $cbUri
                        )
                    ),
                    $nsDssn . 'subscriptionHub' => array(
                        array(
                            'type' => 'uri',
                            'value' => $feed->getLinkHub()
                        )
                    ),
                    $nsDssn . 'subscriptionTopic' => array(
                        array(
                            'type' => 'uri',
                            'value' => $feed->getLinkSelf()
                        )
                    ),
                )
            );

            $store->addMultipleStatements($graphUri, $subscription);

            $subscribeStatement = array(
                $subscriberUri => array(
                    $nsDssn . 'subscribedTo' => array(
                        array(
                            'type' => 'uri',
                            'value' => $subUri
                        )
                    )
                )
            );

            $store->addMultipleStatements($graphUri, $subscribeStatement);
            }
        }
    }

    /**
     * Get notifications for a user
     * @param $userUri the uri of the user whose notifications you want to get
     * @return an Array of Xodx_Notification objects
     */
    public function getNotifications ($userUri)
    {
        $bootstrap = $this->_app->getBootstrap();
        $model = $bootstrap->getResource('model');

        $query = 'PREFIX dssn: <http://purl.org/net/dssn/> ' . PHP_EOL;
        $query.= 'SELECT ?uri' . PHP_EOL;
        $query.= 'WHERE {' . PHP_EOL;
        $query.= '  ?uri dssn:notify <' . $userUri . '> .' . PHP_EOL;
        $query.= '}' . PHP_EOL;

        $result = $model->sparqlQuery($query);

        $notificationFactory = new Xodx_NotificationFactory($this->_app);
        $notifications = array();
        foreach ($result as $notification) {
            $notificationUri = $notification['uri'];
            $notifications[$notificationUri] = $notificationFactory->fromModel($notificationUri);
        }

        return $notifications;
    }

    /**
     * This method creates a new object of the class Xodx_User
     * @param $userUri a string which contains the URI of the required user
     * @return Xodx_User instance with the specified URI
     */
    public function getUser ($userUri = null)
    {
        if ($userUri === null) {
            $applicationController = $this->_app->getController('Xodx_ApplicationController');
            $userId = $applicationController->getUser();
            $userUri = $this->_app->getBaseUri() . '?c=user&id=' . $userId;
        }

        if (!isset($this->_users[$userUri])) {

            if (!isset($userId)) {
                $bootstrap = $this->_app->getBootstrap();
                $model = $bootstrap->getResource('model');

                $query = 'PREFIX foaf: <http://xmlns.com/foaf/0.1/> ' . PHP_EOL;
                $query.= 'SELECT ?name' . PHP_EOL;
                $query.= 'WHERE {' . PHP_EOL;
                $query.= '  <' . $userUri . '> foaf:accountName ?name .' . PHP_EOL;
                $query.= '}' . PHP_EOL;

                $result = $model->sparqlQuery($query);
                if (count($result) > 0) {
                    $userId = $result[0]['name'];
                } else {
                    $userId = 'unkown';
                }
            }

            $user = new Xodx_User($userUri);
            $user->setName($userId);

            $this->_users[$userUri] = $user;
        }

        return $this->_users[$userUri];
    }

    /**
     * This function verifies the given credentials for a user
     * @param $userName a string with the username of the user
     * @param $password a string containing the password of the given user
     */
    public function verifyPasswordCredentials ($userName, $password)
    {
        $bootstrap = $this->_app->getBootstrap();
        $model = $bootstrap->getResource('model');

        // TODO prevent sparql injection

        $query = '' .
            'PREFIX ow: <http://ns.ontowiki.net/SysOnt/> ' .
            'PREFIX sioc: <http://rdfs.org/sioc/ns#> ' .
            'PREFIX foaf: <http://xmlns.com/foaf/0.1/> ' .
            'SELECT ?userUri ?passwordHash ' .
            'WHERE { ' .
            '   ?userUri a sioc:UserAccount ; ' .
            '       foaf:accountName "' . $userName . '" ; ' .
            '       ow:userPassword ?passwordHash . ' .
            '}';
        $passwordQueryResult = $model->sparqlQuery($query);

        if (count($passwordQueryResult) > 0) {
            $passwordHash = $passwordQueryResult[0]['passwordHash'];
            return password_verify($password, $passwordHash);
        } else {
            return false;
        }
    }

    /**
     * Check if a user is already subscribed to a feed
     * @param $userUri the uri of the user in question
     * @param $feedUri the uri of the feed in question
     */
    private function _isSubscribed ($userUri, $feedUri)
    {
        $bootstrap = $this->_app->getBootstrap();
        $model = $bootstrap->getResource('model');

        $query = 'PREFIX dssn: <http://purl.org/net/dssn/> ' . PHP_EOL;
        $query.= 'ASK  ' . PHP_EOL;
        $query.= 'WHERE { ' . PHP_EOL;
        $query.= '   <' . $userUri . '> dssn:subscribedTo      ?subUri. ' . PHP_EOL;
        $query.= '        ?subUri       dssn:subscriptionTopic <' . $feedUri. '> . ' . PHP_EOL;
        $query.= '}' . PHP_EOL;
        $subscribedResult = $model->sparqlQuery($query);

        if (count($subscribedResult) > 0) {
            if (is_array($subscribedResult)) {
                // Erfurt problem
                return empty($subscribedResult[0]['__ask_retval']);
            } else if (is_bool($subscribedResult)) {
                return $subscriptionResult;
            } else {
                $logger = $bootstrap->getResource('logger');
                $logger->info('isSubscribed: user: ' . $userUri . ', feed: ' . $feedUri . '. ASK Query returned unexpectedly: ' . var_export($subscriptionResult));

                throw new Exception('Erfurt returned an unexpected result to the ask query.');
            }
        }
        return null;
    }


    /**
     * Find all subscriptions of a user
     * @param $userUri the uri of the user in question
     * @return array $subscribedFeeds all feeds a user is subscribed to
     */
    public function getSubscriptions ($userUri)
    {
        $bootstrap = $this->_app->getBootstrap();
        $model = $bootstrap->getResource('model');

        // SPARQL-Query
        $query = 'PREFIX dssn: <http://purl.org/net/dssn/> ' . PHP_EOL;
        $query.= 'SELECT  ?feedUri' . PHP_EOL;
        $query.= 'WHERE {' . PHP_EOL;
        $query.= '   <' . $userUri . '> dssn:subscribedTo        ?subUri. ' . PHP_EOL;
        $query.= '   ?subUri            dssn:subscriptionTopic   ?feedUri. ' . PHP_EOL;
        $query.= '}' . PHP_EOL;

        $feedResult = $model->sparqlQuery($query);

        $subscribedFeeds = array();

        // results in array
        foreach ($feedResult as $feed) {
            if (isset($feed['feedUri'])) {
                $subscribedFeeds[] = $feed['feedUri'];
            }
        }

        return $subscribedFeeds;
    }

    /**
     * Find all resources a user is subscribed to via Activity Feed
     * @param $userUri the uri of the user in question
     * @return array $subResources all resource a user is subscribed to
     */
    public function getSubscriptionResources ($userUri)
    {
        $bootstrap = $this->_app->getBootstrap();
        $model = $bootstrap->getResource('model');

        // SPARQL-Query
        $query = 'PREFIX dssn: <http://purl.org/net/dssn/> ' . PHP_EOL;
        $query.= 'SELECT  DISTINCT ?resUri' . PHP_EOL;
        $query.= 'WHERE {' . PHP_EOL;
        $query.= '   <' . $userUri . '> dssn:subscribedTo        ?subUri. ' . PHP_EOL;
        $query.= '   ?subUri            dssn:subscriptionTopic   ?feedUri. ' . PHP_EOL;
        $query.= '   ?resUri            dssn:activityFeed   ?feedUri. ' . PHP_EOL;
        $query.= '}' . PHP_EOL;

        $result = $model->sparqlQuery($query);

        $subResources = array();

        // results in array
        foreach ($result as $resource) {
            if (isset($resource['resUri'])) {
                $subResources[] = $resource['resUri'];
            }
        }

        return $subResources;
    }

    /**
     * Get the Uri of a user account of a person
     * @param string $personUri the uri of the person
     * @return string $userUri uri of the found user account
     */
    public function getUserUri ($personUri)
    {
        $bootstrap = $this->_app->getBootstrap();
        $model = $bootstrap->getResource('model');

        // SPARQL-Query
        $query = 'PREFIX foaf: <http://xmlns.com/foaf/0.1/> ' . PHP_EOL;
        $query.= 'SELECT  ?userUri ' . PHP_EOL;
        $query.= 'WHERE {' . PHP_EOL;
        $query.= '   <' . $personUri . '> foaf:account ?userUri. ' . PHP_EOL;
        $query.= '}' . PHP_EOL;

        $userResult = $model->sparqlQuery($query);

        if (count($userResult[0])>0) {
            return $userResult[0]['userUri'];
        } else {
            return null;
        }
    }

    /**
     *
     */
    public function testSubscribeAction ($template){
        $user = $this->_app->getBaseUri() . '?c=user&id=splatte';
        //$feed = 'http://www.lvz-online.de/rss/nachrichten-rss.xml';
        $feed = 'http://t61.comiles.eu/xodx/?c=feed&a=getFeed&uri=http%3A%2F%2Ft61.comiles.eu%2Fxodx%2F%3Fc%3Dperson%26id%3Dsplatte';
        $this->_subscribeToFeed($user,$feed);
        return $template;
    }

}
