<?php
namespace TICademia;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use User;
use Quiz;
use Duel;
use Module;
use ApprovedQuiz;
use ModuleUser;
use Course;
use Achievement;
use ReachedAchievement;
use Notification;

class DuelController implements MessageComponentInterface
{

    protected $courses;
    protected $dictionary;
    protected $fighting;
    protected $fightingStatus;

    public function __construct()

    {
        require 'config/database.php';
        $this->coursesTutors = [];  // $this->courses[$courseID][$tutorID][$connectionID]=$connection
        $this->courses = [];  // $this->courses[$courseID][$userID][$connectionID]=$connection
        $this->dictionary = [];  // $this->dictionary[$connectionID]=['courseID'=>X,'userID'=Y]
        $this->fighting = []; // $this->fighting[$defiantUserID]=$opponentUserID
        $this->fightingStatus = []; // $this->fightingStatus[$defiantUserID.'_'.$opponentUserID]=['defiantAnswer'=>null,'opponentAnswer'=>null]
    }

    public function onOpen(ConnectionInterface $conn)
    {

    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = json_decode($msg);

        switch ($data->action) {
            case 'init':
                $this->init($from, $data);
                break;
            case 'initTutor':
                $this->initTutor($from, $data);
                break;
            case 'getDuel':
                $this->getDuel($from, $data);
                break;
            case 'cancelDuel':
                $this->cancelDuel($from, $data);
                break;
            case 'cancelDuelTimeOff':
                $this->cancelDuelTimeOff($from, $data);
                break;
            case 'rejectDuel':
                $this->rejectDuel($from, $data);
                break;
            case 'acceptDuel':
                $this->acceptDuel($from, $data);
                break;
            case 'answerQuizDuel':
                $this->answerQuizDuel($from, $data);
                break;

        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        $resourceId = $conn->resourceId;

        if (isset($this->dictionary[$resourceId]["courseID"]) && isset($this->dictionary[$resourceId]["userID"])) {
            $courseID = $this->dictionary[$resourceId]["courseID"];
            $userID = $this->dictionary[$resourceId]["userID"];

            unset($this->dictionary[$resourceId]);

            if (isset($this->courses[$courseID][$userID])) {
                unset($this->courses[$courseID][$userID][$resourceId]);

                if (sizeof($this->courses[$courseID][$userID]) == 0) {
                    unset($this->courses[$courseID][$userID]);

                    $this->updateTotalUsersOnline($courseID);
                    $this->checkIfUserWasFighting($courseID, $userID);
                }
            } else if (isset($this->coursesTutors[$courseID][$userID])) {

                unset($this->coursesTutors[$courseID][$userID][$resourceId]);

                if (sizeof($this->coursesTutors[$courseID][$userID]) == 0) {
                    unset($this->coursesTutors[$courseID][$userID]);
                    $this->updateTotalUsersOnline($courseID);
                }
            }
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "An error has occurred: {$e->getMessage()}\n";

        $conn->close();
    }

    /** Custom Functions **/

    private function initTutor(ConnectionInterface $from, $data)
    {
        $resourceId = $from->resourceId; // ID de la conexión entre el cliente y el servidor
        $courseID = $data->courseID;
        $userID = $data->userID;

        $this->dictionary[$resourceId] = ["courseID" => $courseID, "userID" => $userID]; // Mapeo entre el ID de la conexión y el usuario al que pertenece

        $this->coursesTutors[$courseID][$userID][$resourceId] = $from;

        if (!isset($this->courses[$courseID]))
            $this->courses[$courseID] = [];


        $this->updateTotalUsersOnline($courseID);
    }

    private function init(ConnectionInterface $from, $data)
    {
        $resourceId = $from->resourceId; // ID de la conexión entre el cliente y el servidor
        $courseID = $data->courseID;
        $userID = $data->userID;

        $this->dictionary[$resourceId] = ["courseID" => $courseID, "userID" => $userID]; // Mapeo entre el ID de la conexión y el usuario al que pertenece

        $this->courses[$courseID][$userID][$resourceId] = $from;

        if (!isset($this->coursesTutors[$courseID]))
            $this->coursesTutors[$courseID] = [];

        $this->updateTotalUsersOnline($courseID);
    }

    private function getDuel(ConnectionInterface $from, $data)
    {
        $courseID = $data->courseID;
        $userID = $data->userID;

        if (!isset($this->courses[$courseID][$userID])) {
            $data = ["action" => "showNotification",
                "message" => "Debes tener el rol de estudiante para participar en los duelos."];

            $this->sendMessageToUser($courseID, $userID, $data, $from);//Notificamos al usuario que ya se encuentra en un duelo;

        } else if ($this->isUserInDuel($userID))// Si el usuario ya esta en duelo
        {
            $data = ["action" => "showNotification",
                "message" => "Ya te encuentras en un duelo."];

            $this->sendMessageToUser($courseID, $userID, $data, $from);//Notificamos al usuario que ya se encuentra en un duelo
        } else {
            $opponentUserID = $this->findOpponent($courseID, $userID);
            echo date("Y-m-d H:i:s") . ": $userID -> $opponentUserID \n";

            if ($opponentUserID == "unpreparedDefiant") {
                $data = ["action" => "showNotification",
                    "message" => "Debes solucionar al menos 10 ejercicios para poder batirte a duelo con otros estudiantes."];

                $this->sendMessageToUser($courseID, $userID, $data); //Notificar al desafiante
            } else if ($opponentUserID == -1) {
                $data = ["action" => "showNotification",
                    "message" => "No hay oponentes en este momento."];

                $this->sendMessageToUser($courseID, $userID, $data); //Notificar al desafiante
            } else //Si encontro oponte preguntarle si deasea un duelo
            {
                $this->fighting[$userID] = $opponentUserID;

                $defiantUser = User::find($userID);

                $data = ["action" => "askForDuel",
                    "defiantUserAvatar" => $defiantUser->avatarPath(),
                    "defiantUserFullName" => $defiantUser->fullName(),
                    "defiantUserGender" => $defiantUser->gender];

                $this->sendMessageToUser($courseID, $opponentUserID, $data); //Preguntar al posible oponente si desea aceptar el duelo
            }
        }
    }

    private function cancelDuel(ConnectionInterface $from, $data)
    {
        $courseID = $data->courseID;
        $userID = $data->userID;

        $defiantUserID = $userID;

        if (isset($this->fighting[$defiantUserID])) {
            $opponentUserID = $this->fighting[$defiantUserID];

            $defiantUser = User::find($defiantUserID);

            $data = ["action" => "showNotification",
                "message" => "El estudiante {$defiantUser->linkFullName()} ha cancelado el duelo."];

            $this->sendMessageToUser($courseID, $opponentUserID, $data);

            unset($this->fighting[$defiantUserID]);
        }
    }

    private function cancelDuelTimeOff(ConnectionInterface $from, $data)
    {
        $courseID = $data->courseID;
        $userID = $data->userID;

        $defiantUserID = $userID;

        if (isset($this->fighting[$defiantUserID])) {
            $opponentUserID = $this->fighting[$defiantUserID];

            $data = ["action" => "closeAllModals"];

            $this->sendMessageToUser($courseID, $opponentUserID, $data);

            $data = ["action" => "showNotification",
                "message" => "No hay oponentes en este momento."];

            $this->sendMessageToUser($courseID, $defiantUserID, $data);

            unset($this->fighting[$defiantUserID]);
        }
    }

    private function rejectDuel(ConnectionInterface $from, $data)
    {
        $courseID = $data->courseID;
        $userID = $data->userID;

        $opponentUserID = $userID;
        $defiantUserID = array_search($opponentUserID, $this->fighting);

        unset($this->fighting[$defiantUserID]);

        $data = ["action" => "showNotification",
            "message" => "El oponente selecionado ha rechazado el duelo, inténtalo nuevamente."];

        $this->sendMessageToUser($courseID, $defiantUserID, $data); //Notificar al desafiante

        $quizID = $this->getQuizForDuel($courseID, $defiantUserID, $opponentUserID);

        if ($quizID != -1) {
            //Almacenar el registro del duelo rechazado
            $duel = new Duel;
            $duel->course_id = $courseID;
            $duel->quiz_id = $quizID;
            $duel->defiant_user_id = $defiantUserID;
            $duel->opponent_user_id = $opponentUserID;
            $duel->save();
        }
    }

    private function acceptDuel(ConnectionInterface $from, $data)
    {
        $courseID = $data->courseID;
        $userID = $data->userID;

        $opponentUserID = $userID;
        $defiantUserID = array_search($opponentUserID, $this->fighting);

        echo date("Y-m-d H:i:s") . ": $defiantUserID ----> $opponentUserID \n";

        $quizID = $this->getQuizForDuel($courseID, $defiantUserID, $opponentUserID);

        $quiz = Quiz::find($quizID);

        if (!is_null($quiz)) {
            $duel = new Duel;
            $duel->course_id = $courseID;
            $duel->quiz_id = $quiz->id;
            $duel->defiant_user_id = $defiantUserID;
            $duel->opponent_user_id = $opponentUserID;
            $duel->bet = rand(20,40);
            $duel->save();

            $defiantUser = User::find($defiantUserID);
            $opponentUser = User::find($opponentUserID);


            $data = ["action" => "setDuel",
                'quizPath' => $quiz->path($courseID),

                "defiantUserID" => $defiantUser->id,
                "defiantUserFullName" => $defiantUser->fullName(),
                "defiantUserAvatarPath" => $defiantUser->avatarPath(),

                "opponentUserID" => $opponentUser->id,
                "opponentUserFullName" => $opponentUser->fullName(),
                "opponentUserAvatarPath" => $opponentUser->avatarPath(),

                'moduleName' => $quiz->module->name,
                'quizOrder' => $quiz->order,
                'bet' => $duel->bet,

                'modules' => Module::all()->lists('name'),
                'quizzes' => $quiz->module->quizzes->lists('order'),

            ];

            $this->sendMessageToUser($courseID, $defiantUserID, $data); //Notificar al desafiante
            $this->sendMessageToUser($courseID, $opponentUserID, $data); //Notificar al oponente

            $this->fightingStatus[$defiantUserID . "_" . $opponentUserID] = ["defiantAnswer" => null, "opponentAnswer" => null, 'duelID' => $duel->id, 'bet' => $duel->bet];// Seteamos este array, su objetivo es indicar que el duelo inicio y saber en que estado se encuentra
        } else {
            unset($this->fighting[$defiantUserID]);

            $data = ["action" => "showNotification",
                "message" => "El duelo se ha cancelado"];

            $this->sendMessageToUser($courseID, $opponentUserID, $data); //Notificar al desafiante
            $this->sendMessageToUser($courseID, $defiantUserID, $data); //Notificar al desafiante
        }
    }

    private function answerQuizDuel(ConnectionInterface $from, $data)
    {
        $courseID = $data->courseID;
        $userID = $data->userID;
        $quizStatus = $data->quizStatus;

        $defiantUserID = false;
        $opponentUserID = false;

        $this->getDefiantAndOpponent($userID, $defiantUserID, $opponentUserID);

        $defiantUser = User::find($defiantUserID);
        $opponentUser = User::find($opponentUserID);
        $course = Course::find($courseID);

        if ($defiantUserID && $opponentUserID && isset($this->fightingStatus[$defiantUserID . "_" . $opponentUserID]["bet"])) {
            $bet = $this->fightingStatus[$defiantUserID . "_" . $opponentUserID]["bet"];
            if ($userID == $defiantUserID) {
                if ($quizStatus == "correct") {
                    $data = ["action" => "showNotification",
                        "message" => "Felicitaciones! has ganado el duelo. <br><br>Recompensa: <b>$bet puntos</b>."];

                    $this->sendMessageToUser($courseID, $defiantUserID, $data); //Notificar al desafiante

                    $data = ["action" => "showNotification",
                        "message" => "El estudiante {$defiantUser->linkFullName()} ha ganado el duelo."];

                    $this->sendMessageToUser($courseID, $opponentUserID, $data); //Notificar al oponente

                    $this->setWinnerDuel($defiantUserID, $opponentUserID, $defiantUserID);

                    unset($this->fighting[$defiantUserID]);
                    unset($this->fightingStatus[$defiantUserID . "_" . $opponentUserID]);
                } else {
                    if ($this->fightingStatus[$defiantUserID . "_" . $opponentUserID]["opponentAnswer"] == 'wrong') {
                        $data = ["action" => "showNotification",
                            "message" => "La respuesta de {$opponentUser->linkFullName()} también fue incorrecta, por lo cual se ha producido un empate."];
                        $this->sendMessageToUser($courseID, $defiantUserID, $data); //Notificar al desafiante

                        $data = ["action" => "showNotification",
                            "message" => "La respuesta de {$defiantUser->linkFullName()} también fue incorrecta, por lo cual se ha producido un empate."];
                        $this->sendMessageToUser($courseID, $opponentUserID, $data); //Notificar al oponente

                        unset($this->fighting[$defiantUserID]);
                        unset($this->fightingStatus[$defiantUserID . "_" . $opponentUserID]);
                    } else {
                        $data = ["action" => "showNotification",
                            "message" => "Tu respuesta no ha sido correcta. <br> <br> El estudiante {$opponentUser->linkFullName()} aún está jugando"];
                        $this->sendMessageToUser($courseID, $defiantUserID, $data); //Notificar al desafiante

                        $this->fightingStatus[$defiantUserID . "_" . $opponentUserID]["defiantAnswer"] = $quizStatus;
                    }
                }
            } else {
                if ($quizStatus == "correct") {
                    $data = ["action" => "showNotification",
                        "message" => "Felicitaciones! has ganado el duelo. <br><br>Recompensa: <b>$bet puntos</b>."];

                    $this->sendMessageToUser($courseID, $opponentUserID, $data); //Notificar al oponente

                    $data = ["action" => "showNotification",
                        "message" => "El estudiante {$opponentUser->linkFullName()} ha ganado el duelo."];

                    $this->sendMessageToUser($courseID, $defiantUserID, $data); //Notificar al desafiante

                    $this->setWinnerDuel($defiantUserID, $opponentUserID, $opponentUserID);

                    unset($this->fighting[$defiantUserID]);
                    unset($this->fightingStatus[$defiantUserID . "_" . $opponentUserID]);
                } else {
                    if ($this->fightingStatus[$defiantUserID . "_" . $opponentUserID]["defiantAnswer"] == 'wrong') {

                        $data = ["action" => "showNotification",
                            "message" => "La respuesta de {$opponentUser->linkFullName()} también fue incorrecta, por lo cual se ha producido un empate."];
                        $this->sendMessageToUser($courseID, $defiantUserID, $data); //Notificar al desafiante


                        $data = ["action" => "showNotification",
                            "message" => "La respuesta de {$defiantUser->linkFullName()} también fue incorrecta, por lo cual se ha producido un empate."];
                        $this->sendMessageToUser($courseID, $opponentUserID, $data); //Notificar al oponente

                        unset($this->fighting[$defiantUserID]);
                        unset($this->fightingStatus[$defiantUserID . "_" . $opponentUserID]);
                    } else {
                        $data = ["action" => "showNotification",
                            "message" => "Tu respuesta no ha sido correcta. <br> <br> El estudiante {$defiantUser->linkFullName()} aún está jugando."];

                        $this->sendMessageToUser($courseID, $opponentUserID, $data); //Notificar al desafiante

                        $this->fightingStatus[$defiantUserID . "_" . $opponentUserID]["opponentAnswer"] = $quizStatus;
                    }
                }
            }
        }

        if (!is_null($defiantUser))
            $this->checkAchievements($defiantUser, $course);
        if (!is_null($opponentUser))
            $this->checkAchievements($opponentUser, $course);
    }

    private function updateTotalUsersOnline($courseID)
    {
        $data = ["action" => "updateTotalUsersOnline",
            "totalUsersOnline" => sizeof($this->courses[$courseID]) + sizeof($this->coursesTutors[$courseID])];

        $data = json_encode($data);

        foreach ($this->courses[$courseID] as $userConnections) {
            foreach ($userConnections as $connection) {
                $connection->send($data);
            }
        }
        foreach ($this->coursesTutors[$courseID] as $userConnections) {
            foreach ($userConnections as $connection) {
                $connection->send($data);
            }
        }
    }

    private function findOpponent($courseID, $defiantUserID)
    {
        $totalUsersOnline = sizeof($this->courses[$courseID]);

        if ($totalUsersOnline <= 1)
            return -1;

        $defiantUserApprovedQuizzes = ApprovedQuiz::join('quizzes', 'quizzes.id', '=', 'approved_quizzes.quiz_id')
            ->join('modules', 'modules.id', '=', 'quizzes.module_id')
            ->where('approved_quizzes.user_id', $defiantUserID)
            ->where('modules.course_id', $courseID)
            ->select('quizzes.id')
            ->distinct('quizzes.id')
            ->lists('quizzes.id');

        if (sizeof($defiantUserApprovedQuizzes) < 10)
            return "unpreparedDefiant";


        $flag = 0;
        while ($flag++ <= $totalUsersOnline * $totalUsersOnline) {
            $opponentUserID = array_rand($this->courses[$courseID]);
            if ($opponentUserID != $defiantUserID && !array_key_exists($opponentUserID, $this->fighting) && !in_array($opponentUserID, $this->fighting))// Verificamos que el posible oponente no sea el mismo retador y además que no se encuentre en un duelo
            {

                $opponentUserApprovedQuizzes = ApprovedQuiz::join('quizzes', 'quizzes.id', '=', 'approved_quizzes.quiz_id')
                    ->join('modules', 'modules.id', '=', 'quizzes.module_id')
                    ->where('approved_quizzes.user_id', $opponentUserID)
                    ->where('modules.course_id', $courseID)
                    ->select('quizzes.id')
                    ->distinct('quizzes.id')
                    ->lists('quizzes.id');

                $mergeApprovedQuizzes = array_intersect($defiantUserApprovedQuizzes, $opponentUserApprovedQuizzes);

                if (sizeof($defiantUserApprovedQuizzes) >= 10 && sizeof($opponentUserApprovedQuizzes) >= 10 && sizeof($mergeApprovedQuizzes) > 0) {
                    return $opponentUserID;
                }
            }
        }

        return -1;
    }

    private function sendMessageToUser($courseID, $userID, $data, $specificConnection = null)
    {
        $data = json_encode($data);
        if (is_null($specificConnection) && isset($this->courses[$courseID][$userID])) {
            foreach ($this->courses[$courseID][$userID] as $connection) {
                $connection->send($data);
            }
        } else if (!is_null($specificConnection)) {
            $specificConnection->send($data);
        }
    }

    private function checkIfUserWasFighting($courseID, $userID)
    {
        $defiantUserID = false;
        $opponentUserID = false;

        $this->getDefiantAndOpponent($userID, $defiantUserID, $opponentUserID);

        if ($defiantUserID && $opponentUserID) {

            $defiantUser = User::find($defiantUserID);
            $opponentUser = User::find($opponentUserID);
            $course = Course::find($courseID);

            if ($userID == $defiantUserID)//Si el usuario desconectado es el desafiante notificar al oponente
            {


                if (isset($this->fightingStatus[$defiantUserID . "_" . $opponentUserID]) && is_null($this->fightingStatus[$defiantUserID . "_" . $opponentUserID]['defiantAnswer']))// Si el duelo ya inicio y el usuario desafiante no ha enviado la respuesta dar por ganador al usuario oponente
                {
                    $bet = $this->fightingStatus[$defiantUserID . "_" . $opponentUserID]["bet"];

                    $this->setWinnerDuel($defiantUserID, $opponentUserID, $opponentUserID);

                    $data = ["action" => "showNotification",
                        "message" => "Has ganado el duelo, el estudiante {$defiantUser->linkFullName()} se ha desconectado. <br><br> Recompensa: <b>$bet puntos</b>"];

                    $this->sendMessageToUser($courseID, $opponentUserID, $data); //Notificar al oponente

                    unset($this->fighting[$defiantUserID]);

                } else if (!isset($this->fightingStatus[$defiantUserID . "_" . $opponentUserID])) // de lo contrario; si el duelo no ha iniciado cancelarlo
                {
                    $data = ["action" => "showNotification",
                        "message" => "Duelo cancelado, el estudiante retador se ha desconectado"];

                    $this->sendMessageToUser($courseID, $opponentUserID, $data); //Notificar al oponente

                    unset($this->fighting[$defiantUserID]);
                }

            } else//de lo contrario el usuario desconectado es el oponente
            {


                if (isset($this->fightingStatus[$defiantUserID . "_" . $opponentUserID]) && is_null($this->fightingStatus[$defiantUserID . "_" . $opponentUserID]['opponentAnswer']))// Si el duelo ya inicio  y el usuario oponente no ha enviado la respuesta dar por ganador al usuario desafiante
                {
                    $bet = $this->fightingStatus[$defiantUserID . "_" . $opponentUserID]["bet"];

                    $this->setWinnerDuel($defiantUserID, $opponentUserID, $defiantUserID);

                    $data = ["action" => "showNotification",
                        "message" => "Has ganado el duelo, el estudiante {$opponentUser->linkFullName()} se ha desconectado. <br><br>Recompensa: <b>$bet puntos</b>"];

                    $this->sendMessageToUser($courseID, $defiantUserID, $data); //Notificar al desafiante

                    unset($this->fighting[$defiantUserID]);

                } else if (!isset($this->fightingStatus[$defiantUserID . "_" . $opponentUserID])) {
                    $data = ["action" => "showNotification",
                        "message" => "Duelo cancelado, el oponente selecionado se ha desconectado, inténtalo nuevamente"];

                    $this->sendMessageToUser($courseID, $defiantUserID, $data); //Notificar al desafiante

                    unset($this->fighting[$defiantUserID]);
                }
            }
            if (!is_null($defiantUser))
                $this->checkAchievements($defiantUser, $course);
            if (!is_null($opponentUser))
                $this->checkAchievements($opponentUser, $course);
        }

    }

    private function getDefiantAndOpponent($userID, &$defiantUserID, &$opponentUserID)
    {
        if (array_key_exists($userID, $this->fighting)) {
            $defiantUserID = $userID;
            $opponentUserID = $this->fighting[$defiantUserID];
        } else {
            $defiantUserID = array_search($userID, $this->fighting);
            if ($defiantUserID)
                $opponentUserID = $userID;
        }
    }

    private function isUserInDuel($userID)
    {
        return array_key_exists($userID, $this->fighting) || in_array($userID, $this->fighting);
    }

    private function setWinnerDuel($defiantUserID, $opponentUserID, $winnerUserID)
    {
        if (isset($this->fightingStatus[$defiantUserID . "_" . $opponentUserID]['duelID'])) {
            $duel = Duel::find($this->fightingStatus[$defiantUserID . "_" . $opponentUserID]['duelID']);
            $duel->winner_user_id = $winnerUserID;
            $duel->save();

            $moduleID = $duel->quiz->module_id;

            $moduleUser = ModuleUser::where('user_id', $winnerUserID)->where('module_id', $moduleID)->first();
		if ($moduleUser) {
            		$moduleUser->score += $duel->bet;
            		$moduleUser->save();

            		$user = User::find($winnerUserID);
            		$this->updateCourseUserScore($user, $duel->quiz->module->course_id, $duel->bet);
		}
        }
    }

    private function getQuizForDuel($courseID, $defiantUserID, $opponentUserID)
    {
        $defiantUserApprovedQuizzes = ApprovedQuiz::join('quizzes', 'quizzes.id', '=', 'approved_quizzes.quiz_id')
            ->join('modules', 'modules.id', '=', 'quizzes.module_id')
            ->where('approved_quizzes.user_id', $defiantUserID)
            ->where('modules.course_id', $courseID)
            ->select('quizzes.id')
            ->distinct('quizzes.id')
            ->lists('quizzes.id');

        $opponentUserApprovedQuizzes = ApprovedQuiz::join('quizzes', 'quizzes.id', '=', 'approved_quizzes.quiz_id')
            ->join('modules', 'modules.id', '=', 'quizzes.module_id')
            ->where('approved_quizzes.user_id', $opponentUserID)
            ->where('modules.course_id', $courseID)
            ->select('quizzes.id')
            ->distinct('quizzes.id')
            ->lists('quizzes.id');

        $mergeApprovedQuizzes = array_intersect($defiantUserApprovedQuizzes, $opponentUserApprovedQuizzes);

        if (sizeof($mergeApprovedQuizzes) > 0)
            return $mergeApprovedQuizzes[array_rand($mergeApprovedQuizzes)];
        else
            return -1;

    }

    /* Functions  Achievements  */

    private function checkAchievements($user, $course)
    {

        $totalDuels = Duel::where('course_id', $course->id)
            ->where(function ($query) use ($user) {
                $query->where('defiant_user_id', $user->id)
                    ->orWhere('opponent_user_id', $user->id);
            })->where('bet', '<>', 0)
            ->count();


        $achievementID = -1;

        switch ($totalDuels) {
            case 1:
                $achievementID = 53;
                break;

            case 5:
                $achievementID = 54;
                break;

            case 15:
                $achievementID = 55;
                break;
            case 30:
                $achievementID = 56;
                break;
        }

        if ($achievementID != -1 && $this->dontHaveTheAchievement($user, $course, $achievementID))//Si no tiene el logro para este curso
            $this->giveAchievement($user, $course, $achievementID);//La validación se hizo antes de invocar este método

        $wonDuels = Duel::where('course_id', $course->id)
            ->where('winner_user_id', $user->id)
            ->count();

        $achievementID = -1;

        switch ($wonDuels) {
            case 6:
                $achievementID = 57;
                break;

            case 12:
                $achievementID = 58;
                break;

            case 24:
                $achievementID = 59;
                break;
        }

        if ($achievementID != -1 && $this->dontHaveTheAchievement($user, $course, $achievementID))//Si no tiene el logro para este curso
            $this->giveAchievement($user, $course, $achievementID);//La validación se hizo antes de invocar este método

    }

    private function dontHaveTheAchievement($user, $course, $achievementID)
    {
        $reachedAchievement = ReachedAchievement::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('achievement_id', $achievementID)
            ->first();

        return is_null($reachedAchievement);
    }

    private function giveAchievement($user, $course, $achievementID)
    {
        $reachedAchievement = new ReachedAchievement;
        $reachedAchievement->user_id = $user->id;
        $reachedAchievement->course_id = $course->id;
        $reachedAchievement->achievement_id = $achievementID;
        $reachedAchievement->save();

        $achievement = Achievement::findOrFail($achievementID);

        $this->setNotification($user, $achievementID, $course->id, $reachedAchievement->id);
        $this->updateCourseUserScore($user, $course->id, $achievement->reward);
    }

    private function updateCourseUserScore($user, $courseID, $score)
    {
        $currentScore = $user->courses->find($courseID)->pivot->score;
        $newScore = $currentScore + $score;
        $user->courses()->updateExistingPivot($courseID, ['score' => $newScore]);
    }

    private function setNotification($user, $achievementID, $courseID, $reachedAchievementID)
    {

        $achievement = Achievement::findOrFail($achievementID);

        $notification = new Notification;
        $notification->user_id = $user->id;
        $notification->title = 'Has ganado un nuevo logro';
        $notification->image = $achievement->imagePath();
        $notification->url = "curso/{$courseID}/logros";
        $notification->body = "Felicitaciones! Has ganado el logro: <b>{$achievement->name}</b>.<br> Descripción: {$achievement->description} <br><br> Recompensa: <b>{$achievement->reward} puntos</b>";
        $notification->reached_achievement_id = $reachedAchievementID;
        $notification->show_modal = 1;
        $notification->save();
    }
}
