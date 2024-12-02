window.addEventListener("DOMContentLoaded", function () {
    this.document.querySelectorAll("td").forEach(function (cell) {
        cell.addEventListener("click", placeMark);
    })
    this.document.querySelector("#send-join-request").addEventListener("click", joinGame);
    this.document.querySelector("#join-code-input").value = "";
})

let end = false;
let mark = null;
let msg = {};
let board = [
    [null, null, null],
    [null, null, null],
    [null, null, null]
]
const errorContainer = document.querySelector("#error");
const infoContainer = document.querySelector("#info");

let socket = new WebSocket(`ws://${window.location.hostname}:8081`);
// dodaj pokazywanie błędu, jeżeli nie uda się otworzyc websocketa!!!

socket.addEventListener("open", function () {
    msg.request = "get-join-code";
    socket.send(JSON.stringify(msg));
});


socket.addEventListener("message", (event) => {
    let msg = JSON.parse(event.data);

    if (!end) { // to nie jest za dobry sposób, bo w ogóle serwer nie wie o niczym i zalegają na nim kolejne i kolejne "niedokończone" gry, ale juz mi sie nie chce tego zmieniać
        switch (msg.response) {
            case "return-join-code":
                document.querySelector("#join-code").innerHTML = msg.code;
                break;

            case "error":
                console.log(msg);
                errorContainer.textContent = msg.message
                break;

            case "game-joined":
                console.log(msg);
                mark = "o";
                document.querySelector("#hide-after-join").style.display = "none";
                infoContainer.textContent = msg.message;
                break;

            case "opponent-move":
                console.log(msg);
                if (mark == "x") {
                    addMarkToBoard("o", msg.row, msg.col);
                } else {
                    addMarkToBoard("x", msg.row, msg.col);
                }
                checkEnd();

                break;

            case "move-accepted":
                addMarkToBoard(mark, msg.row, msg.col);
                checkEnd();
                break;

            case "player-2-joined":
                console.log(msg);
                mark = "x";
                infoContainer.textContent = msg.message;
                document.querySelector("#hide-after-join").style.display = "none";
                break;
        }
    }
});

function checkEnd() {
    let result = "";
    let winner = checkTicTacToe(board)

    if (winner == "x" && mark == "x") {
        result = "Wygrywa krzyżyk, gratulacje!";
        end = true;
    }

    if (winner == "o" && mark == "o") {
        result = "Wygrywa kółko, gratulacje!";
        end = true;
    }

    if (winner == "o" && mark == "x") {
        result = "Wygrywa kółko, przegrywasz!";
        end = true;
    }

    if (winner == "x" && mark == "o") {
        result = "Wygrywa krzyżyk, przegrywasz!";
        end = true;
    }

    if (winner == "Draw") {
        result = "Remis!";
        end = true;
    }

    infoContainer.innerText = result;
}

function checkTicTacToe(board) {
    const size = board.length; // Rozmiar planszy (np. 3x3)
    const checkLine = (line) => line.every(cell => cell === "x") || line.every(cell => cell === "o");

    // Sprawdzenie wierszy i kolumn
    for (let i = 0; i < size; i++) {
        if (checkLine(board[i])) return board[i][0]; // Wiersz
        if (checkLine(board.map(row => row[i]))) return board[0][i]; // Kolumna
    }

    // Sprawdzenie przekątnych
    const mainDiagonal = board.map((row, i) => row[i]);
    const antiDiagonal = board.map((row, i) => row[size - 1 - i]);
    if (checkLine(mainDiagonal)) return mainDiagonal[0];
    if (checkLine(antiDiagonal)) return antiDiagonal[0];

    // Sprawdzenie, czy gra może się toczyć dalej
    if (board.some(row => row.includes(null) || row.includes(""))) {
        return "Continue";
    }

    // Jeśli brak zwycięzcy i plansza pełna, remis
    return "Draw";
}

function joinGame() {
    socket.send(JSON.stringify({
        "request": "join-game",
        "joinCode": document.querySelector("#join-code-input").value
    }));
}

function addMarkToBoard(mark, row, col) {
    document.querySelector("tbody").children[row].children[col].innerText = mark;
    board[row][col] = mark;
}

function placeMark() {
    if (!end) {
        msg.request = "place-mark";
        msg.row = this.parentElement.rowIndex;
        msg.col = this.cellIndex;
        console.log(msg)
        socket.send(JSON.stringify(msg));
    }
}