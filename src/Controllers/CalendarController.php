<?php

namespace App\Controllers;

use App\Attribute\RenderAttribute;
use App\Attribute\RouteAttribute;
use App\Core\TwigRenderer;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;
use Sabre\VObject\Component\VCalendar;

#[RenderAttribute(TwigRenderer::class)]
final class CalendarController extends BaseController
{
    #[RouteAttribute(method: "GET", path: "/ical/[a:token]", name: "calendar.ical")]
    public function ical(ServerRequestInterface $request, string $token): Response
    {
        $pdo = $this->database->getConnection();

        // 1. Rechercher l'utilisateur actif par son jeton
        $stmtUser = $pdo->prepare("SELECT id, firstname, name FROM users WHERE calendar_token = :token AND active = 1");
        $stmtUser->execute(['token' => $token]);
        $user = $stmtUser->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Flux de calendrier introuvable ou compte désactivé.');
        }

        // 2. Récupérer les créneaux d'inscription actifs du bénévole
        $stmtAppointments = $pdo->prepare("
            SELECT a.id as appointment_id, a.date, a.start_time, a.end_time,
                   s.name as service_name, s.description as service_description
            FROM appointments_users au
            JOIN appointment a ON a.id = au.aid
            JOIN services s ON s.id = a.sid
            WHERE au.uid = :uid AND au.presence != 'absent'
            ORDER BY a.date ASC, a.start_time ASC
        ");
        $stmtAppointments->execute(['uid' => $user['id']]);
        $appointmentsData = $stmtAppointments->fetchAll(\PDO::FETCH_ASSOC);

        // 3. Récupérer les autres inscrits pour chaque créneau
        $stmtAttendees = $pdo->prepare("
            SELECT u.firstname, u.name 
            FROM appointments_users au
            JOIN users u ON u.id = au.uid
            WHERE au.aid = :aid AND au.uid != :uid AND au.presence != 'absent'
            ORDER BY u.firstname ASC, u.name ASC
        ");

        // 4. Construire le calendrier avec Sabre/VObject
        $vcalendar = new VCalendar();
        $vcalendar->PRODID = '-//Planning Benevoles//Calendar//FR';
        $vcalendar->CALSCALE = 'GREGORIAN';
        $vcalendar->METHOD = 'PUBLISH';
        $vcalendar->{'X-WR-CALNAME'} = 'Planning Bénévoles';
        $vcalendar->{'X-WR-TIMEZONE'} = 'Europe/Paris';

        $tz = new \DateTimeZone('Europe/Paris');

        foreach ($appointmentsData as $app) {
            $stmtAttendees->execute([
                'aid' => $app['appointment_id'],
                'uid' => $user['id']
            ]);
            $attendees = $stmtAttendees->fetchAll(\PDO::FETCH_ASSOC);

            // Construire la description
            $description = $app['service_description'] ?: 'Aucune description.';
            if (!empty($attendees)) {
                $description .= "\n\nAutres participants :\n";
                foreach ($attendees as $att) {
                    $description .= '- ' . $att['firstname'] . ' ' . $att['name'] . "\n";
                }
            }

            // Construire les dates de début et fin
            $dtStart = new \DateTime($app['date'] . ' ' . $app['start_time'], $tz);
            $dtEnd = new \DateTime($app['date'] . ' ' . $app['end_time'], $tz);

            $vevent = $vcalendar->add('VEVENT', [
                'UID'         => 'appointment-' . $app['appointment_id'] . '@planning-benevoles.fr',
                'DTSTART'     => $dtStart,
                'DTEND'       => $dtEnd,
                'SUMMARY'     => $app['service_name'],
                'DESCRIPTION' => $description,
            ]);

            foreach ($attendees as $att) {
                $vevent->add('ATTENDEE', 'mailto:no-reply@planning-benevoles.fr', [
                    'CN'       => $att['firstname'] . ' ' . $att['name'],
                    'ROLE'     => 'REQ-PARTICIPANT',
                    'PARTSTAT' => 'ACCEPTED',
                ]);
            }
        }

        $body = $vcalendar->serialize();

        return new Response(
            200,
            [
                'Content-Type' => 'text/calendar; charset=utf-8',
                'Content-Disposition' => 'inline; filename="planning.ics"',
                'Cache-Control' => 'no-cache, must-revalidate',
                'Expires' => 'Sat, 26 Jul 1997 05:00:00 GMT'
            ],
            $body
        );
    }
}

