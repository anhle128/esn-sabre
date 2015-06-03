<?php

namespace ESN\JSON;

require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/HTTP/ResponseMock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/HTTP/SapiMock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/DAVACL/PrincipalBackend/Mock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/CalDAV/Backend/Mock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/CardDAV/Backend/Mock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/DAVServerTest.php';

/**
 * @medium
 */
class PluginTest extends \PHPUnit_Framework_TestCase {

    protected $caldavCalendar = array(
        'name' => 'Calendar',
        'principaluri' => 'principals/users/54b64eadf6d7d8e41d263e0f',
        'uri' => 'calendar1',
    );

    protected $caldavCalendarObjects = array(
        'event1.ics' =>
             'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
CREATED:20120313T142342Z
UID:171EBEFC-C951-499D-B234-7BA7D677B45D
DTEND;TZID=Europe/Berlin:20120227T000000
TRANSP:OPAQUE
SUMMARY:Monday 0h
DTSTART;TZID=Europe/Berlin:20120227T000000
DTSTAMP:20120313T142416Z
SEQUENCE:4
END:VEVENT
END:VCALENDAR
',
    );

    protected $timeRangeData = [
          'match' => [ 'start' => '20120225T230000Z', 'end' => '20130228T225959Z' ],
          'scope' => [ 'calendars' => [ '/calendars/54b64eadf6d7d8e41d263e0f/calendar1' ] ]
        ];

    protected $carddavAddressBook = array(
        'uri' => 'book1',
        'principaluri' => 'principals/users/54b64eadf6d7d8e41d263e0f',
    );

    protected $carddavCards = array(
        "card1" => "BEGIN:VCARD\r\nFN:d\r\nEND:VCARD\r\n",
        "card2" => "BEGIN:VCARD\r\nFN:c\r\nEND:VCARD",
        "card3" => "BEGIN:VCARD\r\nFN:b\r\nEND:VCARD\r\n",
        "card4" => "BEGIN:VCARD\nFN:a\nEND:VCARD\n",
    );

    function setUp() {
        $mcesn = new \MongoClient(ESN_MONGO_ESNURI);
        $this->esndb = $mcesn->selectDB(ESN_MONGO_ESNDB);

        $mcsabre = new \MongoClient(ESN_MONGO_SABREURI);
        $this->sabredb = $mcsabre->selectDB(ESN_MONGO_SABREDB);

        $this->sabredb->drop();
        $this->esndb->drop();

        $this->esndb->users->insert([ '_id' => new \MongoId('54b64eadf6d7d8e41d263e0f') ]);

        $this->principalBackend = new \ESN\DAVACL\PrincipalBackend\Mongo($this->esndb);
        $this->caldavBackend = new \ESN\CalDAV\Backend\Mongo($this->sabredb);
        $this->carddavBackend = new \ESN\CardDAV\Backend\Mongo($this->sabredb);

        $this->tree[] = new \ESN\CardDAV\AddressBookRoot(
            $this->principalBackend,
            $this->carddavBackend,
            $this->esndb
        );
        $this->tree[] = new \ESN\CalDAV\CalendarRoot(
            $this->principalBackend,
            $this->caldavBackend,
            $this->esndb
        );

        $this->server = new \Sabre\DAV\Server($this->tree);
        $this->server->sapi = new \Sabre\HTTP\SapiMock();
        $this->server->debugExceptions = true;

        $caldavPlugin = new \Sabre\CalDAV\Plugin();
        $this->server->addPlugin($caldavPlugin);

        $this->carddavPlugin = new \Sabre\CardDAV\Plugin();
        $this->server->addPlugin($this->carddavPlugin);

        $plugin = new Plugin('json');
        $this->server->addPlugin($plugin);


        $cal = $this->caldavCalendar;
        $cal['id'] = $this->caldavBackend->createCalendar($cal['principaluri'], $cal['uri'], []);

        foreach ($this->caldavCalendarObjects as $eventUri => $data) {
            $this->caldavBackend->createCalendarObject($cal['id'], $eventUri, $data);
        }
        $book = $this->carddavAddressBook;
        $book['id'] = $this->carddavBackend->createAddressBook($book['principaluri'], $book['uri'], []);

        foreach ($this->carddavCards as $card => $data) {
            $this->carddavBackend->createCard($book['id'], $card, $data);
        }
    }

    function request($request) {

        if (is_array($request)) {
            $request = HTTP\Request::createFromServerArray($request);
        }
        $this->server->httpRequest = $request;
        $this->server->httpResponse = new \Sabre\HTTP\ResponseMock();
        $this->server->exec();

        return $this->server->httpResponse;

    }

    function testTimeRangeQuery() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'REQUEST_URI'       => '/json/queries/time-range',
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $this->assertCount(1, $jsonResponse);
    }

    function testTimeRangeQuery404() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'REQUEST_URI'       => '/json/queries/time-range',
        ));

        $this->timeRangeData['scope']['calendars'] = ['/calendars/54b64eadf6d7d8e41d263e0f/calendar1/event.ics'];

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);
        $this->assertEquals($response->status, 404);
    }

    function testTimeRangeQueryOutsideRoot() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'REQUEST_URI'       => '/jaysun/queries/time-range',
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);
        $this->assertEquals($response->status, 501);
    }

    function testTimeRangeQueryUnknownQuery() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'REQUEST_URI'       => '/json/queries/invalid',
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);
        $this->assertEquals($response->status, 501);
    }
    function testContactsUnknown() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.jaysun'
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);
        $this->assertEquals($response->status, 404);
    }
    function testContactsWrongCollection() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json'
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);
        $this->assertEquals($response->status, 404);
    }

    function testAllContacts() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);
        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json');
        $cards = $jsonResponse->{'_embedded'}->{'dav:item'};
        $this->assertEquals(count($cards), 4);
        $this->assertEquals($cards[0]->{'_links'}->self, '/addressbooks/54b64eadf6d7d8e41d263e0f/book1/card1');
        $this->assertEquals($cards[0]->data[0], 'vcard');
        $this->assertEquals($cards[0]->data[1][0][3], 'd');
    }

    function testOffsetContacts() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json?limit=1&offset=1&sort=fn'
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);
        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json');
        $cards = $jsonResponse->{'_embedded'}->{'dav:item'};
        $this->assertCount(1, $cards);
        $this->assertEquals($cards[0]->{'_links'}->self, '/addressbooks/54b64eadf6d7d8e41d263e0f/book1/card3');
        $this->assertEquals($cards[0]->data[0], 'vcard');
        $this->assertEquals($cards[0]->data[1][0][3], 'b');
    }
}
