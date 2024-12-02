<?php
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;

require __DIR__ . '/vendor/autoload.php';

// defincja klasy z grą: stan planszy, kod dołączania i id graczy od kółka i krzyżyka
class Game
{
    public $boardState = [
        [null, null, null],
        [null, null, null],
        [null, null, null],
    ];

    public $joinCode = null;

    public $turn = true;  // false to kolejka krzyżyka, true to kolejka kółka

    public function __construct($x_player, $o_player, $joinCode)
    {
        $this->x_player = $x_player;
        $this->o_player = $o_player;
        $this->joinCode = $joinCode;
    }
}

$games = array();  // tablica na obiekty Game

class WebSocketsServer implements MessageComponentInterface
{
    protected $clients;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage;
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        global $games;

        // var_dump($msg);
        // foreach ($this->clients as $client) {
        //     if ($from !== $client) {
        //         $client->send($msg);
        //     }
        // }
        $msg = json_decode($msg);

        switch ($msg->request) {
            case 'get-join-code':
                $code = rand(10000, 99999);
                $res = [
                    'response' => 'return-join-code',
                    'code' => $code  // pamiętaj o mozliwości kolizji, ogarnij kiedyś zabezpieczenie
                ];

                echo 'bedzie wydawany kod';
                // echo get_class($from);
                $game = new Game($from, null, $code);
                array_push($games, $game);
                // var_dump($games);
                $from->send(json_encode($res));
                echo json_encode($res);
                break;

            case 'join-game':
                $code = $msg->joinCode;
                if (!preg_match('/^[0-9]{5}$/', $code)) {  // sprawdzamy czy kod to 5 cyfr
                    $res = [
                        'response' => 'error',
                        'message' => 'Niepoprawnie skonstruowany kod dołączenia!'
                    ];
                    $from->send(json_encode($res));
                    echo json_encode($res);
                    break;
                }

                echo 'kod = ' . $code . "\n";
                foreach ($games as $game) {
                    var_dump($game->joinCode);

                    if ($game->joinCode == $code) {
                        echo $code . " kod się zgadza\n";

                        // sprawdzamy czy gracz nie próbuje dołączyć do swojej własnej gry
                        if ($game->x_player == $from) {
                            $res = [
                                'response' => 'error',
                                'message' => 'Nie można dołączyć do swojej gry!'
                            ];
                            $from->send(json_encode($res));
                            echo json_encode($res);
                            break 2;  // 2, bo trzeba wyjść z pętli foreach i z całego switcha, niżej to samo
                        }

                        // oba miejsca na graczy są zajęte, to może się wydarzyć, tylko, jak użytkownik poda kod gry, do której już ktoś dołączył
                        if ($game->x_player && $game->o_player) {
                            $res = [
                                'response' => 'error',
                                'message' => 'W podanej grze nie ma miejsca na dołączenie!'
                            ];
                            $from->send(json_encode($res));
                            echo json_encode($res);

                            break 2;
                        } else {  // jest wolne miejsce
                            $game->o_player = $from;
                            echo 'dolaczono, stan gry: x=' . $game->x_player->resourceId . ' o=' . $game->o_player->resourceId . ' kod=' . $game->joinCode . "\n";
                            $res = [
                                'response' => 'game-joined',
                                'message' => 'Dołączono do gry'
                            ];
                            $game->o_player->send(json_encode($res));
                            echo json_encode($res);

                            $res = [
                                'response' => 'player-2-joined',
                                'message' => 'Gracz 2 dołączył do tej gry'
                            ];
                            $game->x_player->send(json_encode($res));
                            echo json_encode($res);

                            // gracz, który dołącza do gry sam też ma stworzoną grę, do której ktoś może dołączyć, więc trzeba tą grę skasować
                            // jeżeli tego sie nie zrobi, to funkcja przetwarzająca ruchy będzie pracować na tej grze a nie na poprawnej, z oboma graczami
                            echo "wchodzimy w pętlę od kasowania\n";
                            for ($i = 0; $i < count($games); $i++) {
                                echo 'gra do może skasowania, stan gry: x=' . $games[$i]->x_player->resourceId . ' o=' . $games[$i]->o_player->resourceId . ' kod=' . $games[$i]->joinCode . "\n";
                                if (($games[$i]->x_player == $from) && ($games[$i]->o_player == null)) {
                                    echo 'bedzie kasowana gra, stan gry: x=' . $games[$i]->x_player->resourceId . ' o=' . $games[$i]->o_player->resourceId . ' kod=' . $games[$i]->joinCode . "\n";
                                    array_splice($games, $i, 1);
                                }
                            }

                            break 2;
                        }
                    }
                }

                // jeżeli przelecieliśmy przez wszystkie gry i kod żadnej sie nie zgadza
                $res = [
                    'response' => 'error',
                    'message' => 'Nie istnieje gra o takim kodzie!'
                ];
                $from->send(json_encode($res));
                echo json_encode($res);
                break;

            case 'place-mark':
                foreach ($games as $game) {
                    if (($game->x_player != $from) && ($game->o_player != $from)) {
                        continue;  // jeżeli w danej grze nie ma gracza, od którego dostajemy wiadomość, to szukamy w następnej
                    }

                    // sprawdzamy, czy współrzędne są 1 cyfrą z przedziału <0, 2>
                    if (!preg_match('/^[0-2]{1}$/', $msg->row) &&
                            !preg_match('/^[0-2]{1}$/', $msg->col)) {
                        $res = [
                            'response' => 'error',
                            'message' => 'Nieprawidłowe współrzędne!'
                        ];
                        $from->send(json_encode($res));
                        echo json_encode($res);
                        break 2;
                    }

                    // jeżeli nie ma drugiego gracza czyli otrzymujemy wiadomość od gracza
                    // który nie dołączył do żadnej gry, ani nikt nie dołaczył do niego
                    if ($game->o_player == null) {
                        $res = [
                            'response' => 'error',
                            'message' => 'Tylko 1 gracz w grze!'
                        ];
                        $from->send(json_encode($res));
                        echo json_encode($res);
                        break 2;
                    }

                    // jeżeli na polu nie jest null,to jest zajęte, to bedzie też sprawdzane po stronie JS
                    if ($game->boardState[$msg->row][$msg->col] != null) {
                        $res = [
                            'response' => 'error',
                            'message' => 'Pole jest zajęte!'
                        ];
                        $from->send(json_encode($res));
                        echo json_encode($res);
                        break 2;
                    }

                    // jeżeli otrzymujemy od krzyżyka, a jest kolejka kółka i na odwrót
                    if ((($from == $game->x_player) && ($game->turn == true)) ||
                            (($from == $game->o_player) && ($game->turn == false))) {
                        echo 'tura przeciwnika, ';
                        $res = [
                            'response' => 'error',
                            'message' => 'Obecnie trwa tura przeciwnika!'
                        ];
                        $from->send(json_encode($res));
                        echo 'stan gry: x=' . $game->x_player->resourceId . ' o=' . $game->o_player->resourceId . ' kod=' . $game->joinCode . "\n";

                        break 2;
                    } else {
                        // otrzymujemy wiadomość od tego gracza, którego tura obecnie trwa
                        echo 'jest git, stan gry: x=' . $game->x_player->resourceId . ' o=' . $game->o_player->resourceId . ' kod=' . $game->joinCode . "\n";

                        // stawianie na planszy w obiekcie
                        if ($from == $game->x_player) {
                            $game->boardState[$msg->row][$msg->col] = 'x';
                            $targetToNotify = $game->o_player;
                        } else {
                            $game->boardState[$msg->row][$msg->col] = 'o';
                            $targetToNotify = $game->x_player;
                        }
                        $game->turn = !$game->turn;  // zamiana tury

                        // powiadamianie gracza o prawidłowym ruchu
                        $res = [
                            'response' => 'move-accepted',
                            'row' => $msg->row,
                            'col' => $msg->col
                        ];
                        $from->send(json_encode($res));
                        
                        // powiadamianie przeciwnika o wykonanym ruchu
                        $res = [
                            'response' => 'opponent-move',
                            'row' => $msg->row,
                            'col' => $msg->col
                        ];
                        $targetToNotify->send(json_encode($res));
                    }

                    // sprawdzanie wygranej!!!
                }
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);
        echo "Connection {$conn->resourceId} has disconnected\n\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }
}

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new WebSocketsServer()
        )
    ),
    8081
);
$server->run();

?>