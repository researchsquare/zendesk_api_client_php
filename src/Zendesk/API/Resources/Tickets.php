<?php

namespace Zendesk\API\Resources;

use Zendesk\API\Exceptions\MissingParametersException;
use Zendesk\API\Exceptions\ResponseException;
use Zendesk\API\Http;
use Zendesk\API\UtilityTraits\InstantiatorTrait;

/**
 * The Tickets class exposes key methods for reading and updating ticket data
 * @package Zendesk\API
 *
 * @method TicketAudits audits()
 * @method TicketComments comments()
 * @method TicketMetrics metrics()
 * @method SatisfactionRatings satisfactionRatings()
 */

class Tickets extends ResourceAbstract
{
    use InstantiatorTrait;

    const OBJ_NAME = 'ticket';
    const OBJ_NAME_PLURAL = 'tickets';

    /**
     * @var TicketAudits
     */
    protected $audits;
    /**
     * @var TicketComments
     */
    protected $comments;
    /**
     * @var TicketMetrics
     */
    protected $metrics;
    /**
     * @var TicketImport
     */
    protected $import;
    /**
     * @var SatisfactionRatings
     */
    protected $satisfactionRatings;
    /*
     * Helpers:
     */

    /**
     * @var array
     */
    protected $lastAttachments = array();

    /**
     * @param HttpClient $client
     */
    public function __construct(\Zendesk\API\HttpClient $client)
    {
        parent::__construct($client);
        $this->comments = new TicketComments($client);
    }

    protected function setUpRoutes()
    {
        parent::setUpRoutes();

        $this->setRoutes([
            'findMany'            => 'tickets/show_many.json',
            'updateMany'          => 'tickets/update_many.json',
            'markAsSpam'          => 'tickets/{id}/mark_as_spam.json',
            'related'             => 'tickets/{id}/related.json',
            'deleteMany'          => 'tickets/destroy_many.json',
            'collaborators'       => 'tickets/{id}/collaborators.json',
            'incidents'           => 'tickets/{id}/incidents.json',
            'problems'            => 'problems.json',
            'export'              => 'exports/tickets.json',
            'problemAutoComplete' => 'problems/autocomplete.json'
        ]);
    }

    /**
     * Find a specific ticket by id or series of ids
     *
     * @param array $params
     *
     * @throws MissingParametersException
     * @throws ResponseException
     * @throws \Exception
     *
     * @return mixed
     */
    public function findMany(array $params = array())
    {
        $queryParams = ['ids' => implode(",", $params['ids'])];

        $extraParams = Http::prepareQueryParams($this->client->getSideload($params), $params);
        $queryParams = array_merge($queryParams, $extraParams);

        $response = Http::send_with_options($this->client, $this->getRoute('findMany'), ['queryParams' => $queryParams]);

        $this->client->setSideload(null);

        return $response;
    }

    /**
     * Find a specific twitter generated ticket by id
     *
     * @param array $params
     *
     * @throws MissingParametersException
     * @throws ResponseException
     * @throws \Exception
     *
     * @return mixed
     */
    public function findTwicket(array $params = array())
    {
        if ($this->lastId != null) {
            $params['id'] = $this->lastId;
            $this->lastId = null;
        }
        if (!$this->hasKeys($params, array('id'))) {
            throw new MissingParametersException(__METHOD__, array('id'));
        }
        $endPoint = Http::prepare('channels/twitter/tickets/' . $params['id'] . '/statuses.json' . (is_array($params['comment_ids']) ? '?' . implode(',',
                    $params['comment_ids']) : ''), $this->client->getSideload($params));
        $response = Http::send_with_options($this->client, $endPoint);
        if ((!is_object($response)) || ($this->client->getDebug()->lastResponseCode != 200)) {
            throw new ResponseException(__METHOD__);
        }
        $this->client->setSideload(null);

        return $response;
    }

    /**
     * Create a ticket
     *
     * @param array $params
     *
     * @throws ResponseException
     * @throws \Exception
     *
     * @return mixed
     */
    public function create(array $params)
    {
        if (count($this->lastAttachments)) {
            $params['comment']['uploads'] = $this->lastAttachments;
            $this->lastAttachments = array();
        }

        return parent::create($params);
    }

    /**
     * Create a ticket from a tweet
     *
     * @param array $params
     *
     * @throws MissingParametersException
     * @throws ResponseException
     * @throws \Exception
     *
     * @return mixed
     */
    public function createFromTweet(array $params)
    {
        if ((!$params['twitter_status_message_id']) || (!$params['monitored_twitter_handle_id'])) {
            throw new MissingParametersException(__METHOD__,
                array('twitter_status_message_id', 'monitored_twitter_handle_id'));
        }
        $endPoint = Http::prepare('channels/twitter/tickets.json');
        $response = Http::send_with_options($this->client, $endPoint, array(self::OBJ_NAME => $params), 'POST');
        if ((!is_object($response)) || ($this->client->getDebug()->lastResponseCode != 201)) {
            throw new ResponseException(__METHOD__,
                ($this->client->getDebug()->lastResponseCode == 422 ? ' (hint: you can\'t create two tickets from the same tweet)' : ''));
        }
        $this->client->setSideload(null);

        return $response;
    }

    /**
     * Update a ticket or series of tickets
     *
     * @param array $updateResourceFields
     *
     * @throws MissingParametersException
     * @throws ResponseException
     * @throws \Exception
     *
     * @return mixed
     */
    public function update($id, array $updateResourceFields = [])
    {
        if (count($this->lastAttachments)) {
            $updateResourceFields['comment']['uploads'] = $this->lastAttachments;
            $this->lastAttachments = array();
        }

        return parent::update($id, $updateResourceFields);
    }

    /**
     * Update a ticket or series of tickets
     *
     * @param array $params
     *
     * @throws MissingParametersException
     * @throws ResponseException
     * @throws \Exception
     *
     * @return mixed
     */
    public function updateMany(array $params)
    {
        if (count($this->lastAttachments)) {
            $params['comment']['uploads'] = $this->lastAttachments;
            $this->lastAttachments = array();
        }

        $resourceUpdateName = self::OBJ_NAME_PLURAL;
        $queryParams = [];
        if (isset($params['ids']) && is_array($params['ids'])) {
            $queryParams['ids'] = implode(",", $params['ids']);
            unset($params['ids']);

            $resourceUpdateName = self::OBJ_NAME;
        }

        $response = Http::send_with_options(
            $this->client,
            $this->getRoute('updateMany'),
            [
                'method' => 'PUT',
                'queryParams' => $queryParams,
                'postFields' => [$resourceUpdateName => $params]
            ]
        );

        return $response;
    }

    /**
     * Mark a ticket as spam
     *
     * @param array $params
     *
     * @throws MissingParametersException
     * @throws ResponseException
     * @throws \Exception
     *
     * @return mixed
     */
    public function markAsSpam(array $params = array())
    {
        $params = $this->addChainedParametersToParams($params, ['id' => get_class($this)]);

        if (!$this->hasKeys($params, array('id'))) {
            throw new MissingParametersException(__METHOD__, array('id'));
        }
        $response = Http::send_with_options($this->client, $this->getRoute('markAsSpam', $params), null, 'PUT');
        // Seems to be a bug in the service, it may respond with 422 even when it succeeds
        if ((!is_object($response)) || ($this->client->getDebug()->lastResponseCode != 200)) {
            throw new ResponseException(__METHOD__,
                ($this->client->getDebug()->lastResponseCode == 422 ? ' (note: there\'s currently a bug in the service so this call may have succeeded; call tickets->find to see if it still exists.)' : ''));
        }
        $this->client->setSideload(null);

        return $response;
    }

    /**
     * Get related ticket information
     *
     * @param array $params
     *
     * @throws MissingParametersException
     * @throws ResponseException
     * @throws \Exception
     *
     * @return mixed
     */
    public function related(array $params = array())
    {
        $params = $this->addChainedParametersToParams($params, ['id' => get_class($this)]);

        if (!$this->hasKeys($params, array('id'))) {
            throw new MissingParametersException(__METHOD__, array('id'));
        }
        // @TODO: this bit can still be DRY'ed up
        $queryParams = Http::prepareQueryParams($this->client->getSideload($params), $params);
        $response = Http::send_with_options($this->client,
            $this->getRoute('related', $params),
            ['queryParams' => $queryParams]
        );

        if ((!is_object($response)) || ($this->client->getDebug()->lastResponseCode != 200)) {
            throw new ResponseException(__METHOD__);
        }
        $this->client->setSideload(null);

        return $response;
    }

    /**
     * Update a ticket or series of tickets
     *
     * @param array $ids
     * @return mixed
     *
     */
    public function deleteMany(array $ids)
    {
        $response = Http::send_with_options(
            $this->client,
            $this->getRoute('deleteMany'),
            [
                'method' => 'DELETE',
                'queryParams' => ['ids' => implode(',', $ids)]
            ]
        );

        return $response;
    }

    /**
     * List collaborators for a ticket
     *
     * @param array $params
     *
     * @throws MissingParametersException
     * @throws ResponseException
     * @throws \Exception
     *
     * @return mixed
     */
    public function collaborators(array $params)
    {
        $params = $this->addChainedParametersToParams($params, ['id' => get_class($this)]);

        if (!$this->hasKeys($params, array('id'))) {
            throw new MissingParametersException(__METHOD__, array('id'));
        }
        $queryParams = Http::prepareQueryParams($this->client->getSideload($params), $params);
        $response = Http::send_with_options($this->client,
            $this->getRoute('collaborators', $params),
            ['queryParams' => $queryParams]
        );

        if ((!is_object($response)) || ($this->client->getDebug()->lastResponseCode != 200)) {
            throw new ResponseException(__METHOD__);
        }
        $this->client->setSideload(null);

        return $response;
    }

    /**
     * List incidents for a ticket
     *
     * @param array $params
     *
     * @throws MissingParametersException
     * @throws ResponseException
     * @throws \Exception
     *
     * @return mixed
     */
    public function incidents(array $params)
    {
        $params = $this->addChainedParametersToParams($params, ['id' => get_class($this)]);

        if (!$this->hasKeys($params, array('id'))) {
            throw new MissingParametersException(__METHOD__, array('id'));
        }
        $queryParams = Http::prepareQueryParams($this->client->getSideload($params), $params);
        $response = Http::send_with_options($this->client,
            $this->getRoute('incidents', $params),
            ['queryParams' => $queryParams]
        );
        if ((!is_object($response)) || ($this->client->getDebug()->lastResponseCode != 200)) {
            throw new ResponseException(__METHOD__);
        }
        $this->client->setSideload(null);

        return $response;
    }


    /**
     * List all problem tickets
     *
     * @param array $params
     *
     * @throws MissingParametersException
     * @throws ResponseException
     * @throws \Exception
     *
     * @return mixed
     */
    public function problems(array $params = [])
    {
        $queryParams = Http::prepareQueryParams($this->client->getSideload($params), $params);
        $response = Http::send_with_options($this->client,
            $this->getRoute('problems', $params),
            ['queryParams' => $queryParams]
        );

        if ((!is_object($response)) || ($this->client->getDebug()->lastResponseCode != 200)) {
            throw new ResponseException(__METHOD__);
        }
        $this->client->setSideload(null);

        return $response;
    }

    /**
     * Add a problem autocomplete
     *
     * @param array $params
     *
     * @throws MissingParametersException
     * @throws ResponseException
     * @throws \Exception
     *
     * @return mixed
     */
    public function problemAutoComplete(array $params)
    {
        if (!$params['text']) {
            throw new MissingParametersException(__METHOD__, array('text'));
        }

        $response = Http::send_with_options(
            $this->client,
            $this->getRoute('problemAutoComplete'),
            [
                'method' => 'POST',
                'postFields' => ['text' => $params['text']]
            ]
        );

        if ((!is_object($response)) || ($this->client->getDebug()->lastResponseCode != 200)) {
            throw new ResponseException(__METHOD__);
        }

        $this->client->setSideload(null);

        return $response;
    }

    /**
     * Incremental ticket exports with a supplied start_time
     *
     * @param array $params
     *
     * @throws MissingParametersException
     * @throws ResponseException
     * @throws \Exception
     *
     * @return mixed
     */
    public function export(array $params)
    {
        if (!$params['start_time']) {
            throw new MissingParametersException(__METHOD__, array('start_time'));
        }

        $queryParams = ["start_time" => $params["start_time"]];

        $response = Http::send_with_options($this->client, $this->getRoute('export'), ["queryParams" => $queryParams]);

        $this->client->setSideload(null);

        return $response;
    }

    /**
     * Syntactic sugar methods:
     * Handy aliases:
     */

    /**
     * @param int|null $id
     *
     * @return Tags
     */
    public function tags($id = null)
    {
        return ($id != null ? $this->client->tags()->setLastId($id) : $this->client->tags());
    }

    /**
     * @param $id
     *
     * @return Tags
     */
    public function tag($id)
    {
        return $this->client->tags()->setLastId($id);
    }

    /**
     * @param array $params
     *
     * @throws ResponseException
     *
     * @return mixed
     */
    public function import(array $params)
    {
        return $this->import->import($params);
    }

    /**
     * @param array $params
     *
     * @throws CustomException
     * @throws MissingParametersException
     * @throws ResponseException
     *
     * @return Tickets
     */
    public function attach(array $params = array())
    {
        if (!$this->hasKeys($params, array('file'))) {
            throw new MissingParametersException(__METHOD__, array('file'));
        }

        $upload = $this->client->attachments()->upload($params);

        if ((!is_object($upload->upload)) || (!$upload->upload->token)) {
            throw new ResponseException(__METHOD__);
        }
        $this->lastAttachments[] = $upload->upload->token;

        return $this;
    }

}
