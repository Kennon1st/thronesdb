<?php

namespace App\Controller;

use App\Entity\Card;
use App\Entity\Comment;
use App\Entity\CommentInterface;
use App\Entity\Cycle;
use App\Entity\Deck;
use App\Entity\DeckInterface;
use App\Entity\Decklist;
use App\Entity\DecklistInterface;
use App\Entity\Faction;
use App\Entity\Tournament;
use App\Entity\User;
use App\Entity\UserInterface;
use App\Services\DeckValidationHelper;
use App\Services\CardsData;
use App\Services\DecklistFactory;
use App\Services\DecklistManager;
use App\Services\Texts;
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Exception;
use PDO;
use Swift_Mailer;
use Swift_Message;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatorInterface;

/**
 * Class SocialController
 * @package App\Controller
 */
class SocialController extends AbstractController
{
    use LocaleAwareTemplating;

    /**
     * Checks to see if a deck can be published in its current saved state
     * If it is, displays the decklist edit form for initial publication of a deck.
     *
     * @Route(
     *     "/deck/publish/{deck_uuid}",
     *     name="deck_publish_form",
     *     methods={"GET"},
     *     requirements={"deck_uuid"="[0-9a-fA-F]{8}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{12}"}
     * )
     *
     * @param TranslatorInterface $translator
     * @param SessionInterface $session
     * @param DeckValidationHelper $deckValidationHelper
     * @param DecklistFactory $decklistFactory
     * @param string $deck_uuid
     * @return RedirectResponse|Response
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function publishFormAction(
        TranslatorInterface $translator,
        SessionInterface $session,
        DeckValidationHelper $deckValidationHelper,
        DecklistFactory $decklistFactory,
        $deck_uuid
    ) {
        /* @var EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        /* @var UserInterface $user */
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException($translator->trans('login_required'));
        }

        /* @var DeckInterface $deck */
        $deck = $em->getRepository(Deck::class)->findOneBy(['uuid' => $deck_uuid]);
        if (!$deck || $deck->getUser()->getId() != $user->getId()) {
            throw $this->createAccessDeniedException($translator->trans('decklist.publish.errors.unauthorized'));
        }

        $yesterday = (new DateTime())->modify('-24 hours');
        if (false && $user->getDateCreation() > $yesterday) {
            $session
                ->getFlashBag()
                ->set('error', $translator->trans('decklist.publish.errors.antispam.newbie'));

            return $this->redirect($this->generateUrl('deck_view', ['deck_uuid' => $deck->getUuid()]));
        }

        $query = $em->createQuery(
            "SELECT COUNT(d) FROM App\Entity\Decklist d WHERE d.dateCreation>:date AND d.user=:user"
        );
        $query->setParameter('date', $yesterday);
        $query->setParameter('user', $user);
        $decklistsSinceYesterday = $query->getSingleScalarResult();

        if (false && $decklistsSinceYesterday > $user->getReputation()) {
            $session
                ->getFlashBag()
                ->set('error', $translator->trans('decklist.publish.errors.antispam.limit'));

            return $this->redirect($this->generateUrl('deck_view', ['deck_uuid' => $deck->getUuid()]));
        }

        $lastPack = $deck->getLastPack();
        if (!$lastPack->getDateRelease() || $lastPack->getDateRelease() > new DateTime()) {
            $session
                ->getFlashBag()
                ->set('error', $translator->trans('decklist.publish.errors.unreleased'));

            return $this->redirect($this->generateUrl('deck_view', ['deck_uuid' => $deck->getUuid()]));
        }

        $problem = $deckValidationHelper->findProblem($deck);
        if ($problem) {
            $session->getFlashBag()->set('error', $translator->trans('decklist.publish.errors.invalid'));

            return $this->redirect($this->generateUrl('deck_view', ['deck_uuid' => $deck->getUuid()]));
        }

        $new_content = json_encode($deck->getSlots()->getContent());
        $new_signature = md5($new_content);
        $old_decklists = $em->getRepository(Decklist::class)->findBy(['signature' => $new_signature]);

        /* @var DecklistInterface $decklist */
        foreach ($old_decklists as $decklist) {
            if (json_encode($decklist->getSlots()->getContent()) == $new_content) {
                $url = $this->generateUrl(
                    'decklist_detail',
                    array(
                        'decklist_id' => $decklist->getId(),
                        'decklist_name' => $decklist->getNameCanonical(),
                    )
                );
                $session
                    ->getFlashBag()
                    ->set('warning', $translator->trans('decklist.publish.warnings.published', array("%url%" => $url)));
            }
        }

        // decklist for the form ; won't be persisted
        $decklist = $decklistFactory->createDecklistFromDeck(
            $deck,
            $deck->getName(),
            $deck->getDescriptionMd()
        );

        $tournaments = $em->getRepository(Tournament::class)->findAll();

        return $this->render(
            'Decklist/decklist_edit.html.twig',
            [
                'url' => $this->generateUrl('decklist_create'),
                'deck' => $deck,
                'decklist' => $decklist,
                'tournaments' => $tournaments,
            ]
        );
    }

    /**
     * Creates a new decklist from a deck (publish action).
     *
     * @Route("/decklist/create", name="decklist_create", methods={"POST"})
     *
     * @param Request $request
     * @param DecklistFactory $decklistFactory
     * @param TranslatorInterface $translator
     * @return RedirectResponse|Response
     * @throws NonUniqueResultException
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function createAction(Request $request, DecklistFactory $decklistFactory, TranslatorInterface $translator)
    {
        /* @var $em EntityManager */
        $em = $this->getDoctrine()->getManager();
        /* @var UserInterface $user */
        $user = $this->getUser();

        $yesterday = (new DateTime())->modify('-24 hours');
        if (false && $user->getDateCreation() > $yesterday) {
            return $this->render(
                'Default/error.html.twig',
                [
                    'pagetitle' => $translator->trans('decklist.publish.errors.pagetitle.spam'),
                    'error' => $translator->trans('decklist.publish.errors.antispam.newbie'),
                ]
            );
        }

        $query = $em->createQuery(
            "SELECT COUNT(d) FROM App\Entity\Decklist d WHERE d.dateCreation>:date AND d.user=:user"
        );
        $query->setParameter('date', $yesterday);
        $query->setParameter('user', $user);
        $decklistsSinceYesterday = $query->getSingleScalarResult();

        if (false && $decklistsSinceYesterday > $user->getReputation()) {
            return $this->render(
                'Default/error.html.twig',
                [
                    'pagetitle' => $translator->trans('decklist.publish.errors.pagetitle.spam'),
                    'error' => $translator->trans('decklist.publish.errors.antispam.limit'),
                ]
            );
        }

        $deck_id = intval(filter_var($request->request->get('deck_id'), FILTER_SANITIZE_NUMBER_INT));

        /* @var DeckInterface $deck */
        $deck = $this->getDoctrine()->getRepository(Deck::class)->find($deck_id);
        if ($user->getId() !== $deck->getUser()->getId()) {
            throw $this->createAccessDeniedException("Access denied to this object.");
        }

        $name = filter_var(
            $request->request->get('name'),
            FILTER_SANITIZE_STRING,
            FILTER_FLAG_NO_ENCODE_QUOTES
        );
        $descriptionMd = trim($request->request->get('descriptionMd'));

        $tournament_id = filter_var($request->request->get('tournament'), FILTER_SANITIZE_NUMBER_INT);
        $tournament = $em->getRepository(Tournament::class)->find($tournament_id);

        $precedent_id = trim($request->request->get('precedent'));
        if (!preg_match('/^\d+$/', $precedent_id)) {
            // route decklist_detail hard-coded
            if (preg_match('/view\/(\d+)/', $precedent_id, $matches)) {
                $precedent_id = $matches[1];
            } else {
                $precedent_id = null;
            }
        }
        $precedent = $precedent_id ? $em->getRepository(Decklist::class)->find($precedent_id) : null;

        try {
            $decklist = $decklistFactory->createDecklistFromDeck($deck, $name, $descriptionMd);
        } catch (Exception $e) {
            return $this->render(
                'Default/error.html.twig',
                [
                    'pagetitle' => "Error",
                    'error' => $e,
                ]
            );
        }

        $decklist->setTournament($tournament);
        $decklist->setPrecedent($precedent);
        $em->persist($decklist);
        $em->flush();

        return $this->redirect(
            $this->generateUrl(
                'decklist_detail',
                array(
                    'decklist_id' => $decklist->getId(),
                    'decklist_name' => $decklist->getNameCanonical(),
                )
            )
        );
    }

    /**
     * Displays the decklist edit form.
     *
     * @Route("/decklist/edit/{decklist_id}", name="decklist_edit", methods={"GET"}, requirements={"decklist_id"="\d+"})
     *
     * @param int $decklist_id
     * @return Response
     */
    public function editFormAction($decklist_id)
    {
        /* @var $em EntityManager */
        $em = $this->getDoctrine()->getManager();

        /* @var UserInterface $user */
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException("Anonymous access denied");
        }

        $decklist = $em->getRepository(Decklist::class)->find($decklist_id);
        if (!$decklist) {
            throw $this->createNotFoundException("Decklist not found");
        }

        if (!$this->isGranted('ROLE_SUPER_ADMIN')
            && $user->getId() !== $decklist->getUser()->getId()) {
            throw $this->createAccessDeniedException("Access denied");
        }

        $tournaments = $this->getDoctrine()->getManager()->getRepository(Tournament::class)->findAll();

        return $this->render(
            'Decklist/decklist_edit.html.twig',
            [
                'url' => $this->generateUrl('decklist_save', ['decklist_id' => $decklist->getId()]),
                'deck' => null,
                'decklist' => $decklist,
                'tournaments' => $tournaments,
            ]
        );
    }

    /**
     * Save the name and description of a decklist by its publisher.
     *
     * @Route(
     *     "/decklist/save/{decklist_id}",
     *     name="decklist_save",
     *     methods={"POST"},
     *     requirements={"decklist_id"="\d+"}
     * )
     *
     * @param Request $request
     * @param Texts $texts
     * @param string $decklist_id
     * @return RedirectResponse
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function saveAction(Request $request, Texts $texts, $decklist_id)
    {
        /* @var EntityManager $em*/
        $em = $this->getDoctrine()->getManager();

        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException("Anonymous access denied");
        }

        $decklist = $em->getRepository(Decklist::class)->find($decklist_id);
        if (!$decklist) {
            throw $this->createNotFoundException("Decklist not found");
        }

        if (!$this->isGranted('ROLE_SUPER_ADMIN')
            && $user->getId() !== $decklist->getUser()->getId()) {
            throw $this->createAccessDeniedException("Access denied");
        }

        $name = trim(
            filter_var(
                $request->request->get('name'),
                FILTER_SANITIZE_STRING,
                FILTER_FLAG_NO_ENCODE_QUOTES
            )
        );
        $name = substr($name, 0, 60);
        if (empty($name)) {
            $name = "Untitled";
        }
        $descriptionMd = trim($request->request->get('descriptionMd'));
        $descriptionHtml = $texts->markdown($descriptionMd);

        $tournament_id = intval(filter_var($request->request->get('tournament'), FILTER_SANITIZE_NUMBER_INT));
        $tournament = $em->getRepository(Tournament::class)->find($tournament_id);

        $precedent_id = trim($request->request->get('precedent'));
        if (!preg_match('/^\d+$/', $precedent_id)) {
            // route decklist_detail hard-coded
            if (preg_match('/view\/(\d+)/', $precedent_id, $matches)) {
                $precedent_id = $matches[1];
            } else {
                $precedent_id = null;
            }
        }
        $precedent = ($precedent_id && $precedent_id != $decklist_id)
            ? $em->getRepository(Decklist::class)->find($precedent_id)
            : null;

        $decklist->setName($name);
        $decklist->setNameCanonical($texts->slugify($name).'-'.$decklist->getVersion());
        $decklist->setDescriptionMd($descriptionMd);
        $decklist->setDescriptionHtml($descriptionHtml);
        $decklist->setPrecedent($precedent);
        $decklist->setTournament($tournament);
        $decklist->setDateUpdate(new DateTime());
        $em->flush();

        return $this->redirect(
            $this->generateUrl(
                'decklist_detail',
                array(
                    'decklist_id' => $decklist_id,
                    'decklist_name' => $decklist->getNameCanonical(),
                )
            )
        );
    }

    /**
     * Deletes a decklist if it has no comment, no vote, no favorite.
     *
     * @Route(
     *     "/decklist/delete/{decklist_id}",
     *     name="decklist_delete",
     *     methods={"POST"},
     *     requirements={"decklist_id"="\d+"}
     * )
     *
     * @param string $decklist_id
     * @param Request $request
     * @return RedirectResponse
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function deleteAction($decklist_id, Request $request)
    {
        /* @var $em EntityManager */
        $em = $this->getDoctrine()->getManager();

        $user = $this->getUser();
        if (!$user) {
            throw new UnauthorizedHttpException("You must be logged in for this operation.");
        }

        /* @var DecklistInterface $decklist */
        $decklist = $em->getRepository(Decklist::class)->find($decklist_id);
        if (!$decklist || $decklist->getUser()->getId() != $user->getId()) {
            throw new UnauthorizedHttpException("You don't have access to this decklist.");
        }

        if ($decklist->getnbVotes() || $decklist->getNbfavorites() || $decklist->getNbcomments()) {
            throw new UnauthorizedHttpException("Cannot delete this decklist.");
        }

        $precedent = $decklist->getPrecedent();

        $children_decks = $decklist->getChildren();
        foreach ($children_decks as $children_deck) {
            $children_deck->setParent($precedent);
        }

        $successor_decklists = $decklist->getSuccessors();
        /* @var DecklistInterface $successor_decklist */
        foreach ($successor_decklists as $successor_decklist) {
            $successor_decklist->setPrecedent($precedent);
        }

        $em->remove($decklist);
        $em->flush();

        return $this->redirect(
            $this->generateUrl(
                'decklists_list',
                array(
                    'type' => 'mine',
                )
            )
        );
    }

    /**
     * @param Request $request
     * @param TranslatorInterface $translator
     * @param CardsData $cardsData
     * @return string
     */
    protected function searchForm(Request $request, TranslatorInterface $translator, CardsData $cardsData)
    {
        $doctrine = $this->getDoctrine();
        $dbh = $doctrine->getConnection();

        $cards_code = $request->query->get('cards');
        $faction_code = filter_var($request->query->get('faction'), FILTER_SANITIZE_STRING);
        $tournament = filter_var($request->query->get('tournament'), FILTER_SANITIZE_NUMBER_INT);
        $author_name = filter_var($request->query->get('author'), FILTER_SANITIZE_STRING);
        $decklist_name = filter_var($request->query->get('name'), FILTER_SANITIZE_STRING);
        $sort = $request->query->get('sort');
        $packs = $request->query->get('packs');

        if (!is_array($packs)) {
            $packs = $dbh->executeQuery("select id from pack")->fetchAll(PDO::FETCH_COLUMN);
        }

        $categories = [];
        $on = 0;
        $off = 0;
        $categories[] = array(
            "label" => $translator->trans('decklist.list.search.allowed.core'),
            "packs" => [],
        );
        $list_cycles = $this->getDoctrine()->getRepository(Cycle::class)->findAll();
        foreach ($list_cycles as $cycle) {
            $size = count($cycle->getPacks());
            if ($cycle->getPosition() == 0 || $size == 0) {
                continue;
            }
            $first_pack = $cycle->getPacks()[0];
            if ($cycle->getCode() == 'core' || ($size === 1 && $first_pack->getName() == $cycle->getName())) {
                $checked = count($packs) ? in_array($first_pack->getId(), $packs) : true;
                if ($checked) {
                    $on++;
                } else {
                    $off++;
                }
                $categories[0]["packs"][] = array(
                    "id" => $first_pack->getId(),
                    "label" => $first_pack->getName(),
                    "checked" => $checked,
                    "future" => $first_pack->getDateRelease() === null,
                );
            } else {
                $category = array("label" => $cycle->getName(), "packs" => []);
                foreach ($cycle->getPacks() as $pack) {
                    $checked = count($packs) ? in_array($pack->getId(), $packs) : true;
                    if ($checked) {
                        $on++;
                    } else {
                        $off++;
                    }
                    $category['packs'][] = array(
                        "id" => $pack->getId(),
                        "label" => $pack->getName(),
                        "checked" => $checked,
                        "future" => $pack->getDateRelease() === null,
                    );
                }
                $categories[] = $category;
            }
        }

        $activeTournamentTiers = $this->getDoctrine()
            ->getRepository(Tournament::class)
            ->findBy(['active' => true]);

        $inactiveTournamentTiers = $this->getDoctrine()
            ->getRepository(Tournament::class)
            ->findBy(['active' => false]);

        $params = array(
            'allowed' => $categories,
            'on' => $on,
            'off' => $off,
            'author' => $author_name,
            'name' => $decklist_name,
            'activeTournamentTiers' => $activeTournamentTiers,
            'inactiveTournamentTiers' => $inactiveTournamentTiers,
        );
        $params['sort_'.$sort] = ' selected="selected"';
        $params['factions'] = $this->getDoctrine()->getRepository(Faction::class)->findAllAndOrderByName();
        $params['faction_selected'] = $faction_code;
        $params['selectedTournament'] = $tournament;

        if (!empty($cards_code) && is_array($cards_code)) {
            $cards = $this->getDoctrine()->getRepository(Card::class)->findAllByCodes($cards_code);

            $params['cards'] = '';
            foreach ($cards as $card) {
                $cardinfo = $cardsData->getCardInfo($card, false, null);
                $params['cards'] .= $this->renderView('Search/card.html.twig', $cardinfo);
            }
        }

        return $this->renderView('Search/form.html.twig', $params);
    }

    /**
     * Displays the lists of decklists.
     *
     * @Route(
     *     "/decklists/{type}/{page}",
     *     name="decklists_list",
     *     methods={"GET"},
     *     defaults={"type"="popular", "page"=1},
     *     requirements={
     *         "type"="find|favorites|mine|recent|halloffame|hottopics|tournament|popular",
     *         "page"="\d+"
     *     }
     * )
     * @param Request $request
     * @param TranslatorInterface $translator
     * @param DecklistManager $decklistManager
     * @param CardsData $cardsData
     * @param int $cacheExpiration
     * @param string $type
     * @param int $page
     * @return Response
     */
    public function listAction(
        Request $request,
        TranslatorInterface $translator,
        DecklistManager $decklistManager,
        CardsData $cardsData,
        int $cacheExpiration,
        $type,
        $page = 1
    ) {

        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($cacheExpiration);

        $decklistManager->setLimit(30);
        $decklistManager->setPage($page);

        $header = '';

        switch ($type) {
            case 'find':
                $header = $this->searchForm($request, $translator, $cardsData);
                $paginator = $decklistManager->findDecklistsWithComplexSearch();
                break;
            case 'favorites':
                $response->setPrivate();
                /* @var UserInterface $user */
                $user = $this->getUser();
                if ($user) {
                    $paginator = $decklistManager->findDecklistsByFavorite($user);
                } else {
                    $paginator = $decklistManager->getEmptyList();
                }
                break;
            case 'mine':
                $response->setPrivate();
                /* @var UserInterface $user */
                $user = $this->getUser();
                if ($user) {
                    $paginator = $decklistManager->findDecklistsByAuthor($user);
                } else {
                    $paginator = $decklistManager->getEmptyList();
                }
                break;
            case 'recent':
                $paginator = $decklistManager->findDecklistsByAge(false);
                break;
            case 'halloffame':
                $paginator = $decklistManager->findDecklistsInHallOfFame();
                break;
            case 'hottopics':
                $paginator = $decklistManager->findDecklistsInHotTopic();
                break;
            case 'tournament':
                $paginator = $decklistManager->findDecklistsInTournaments();
                break;
            case 'popular':
            default:
                $type = 'popular';
                $paginator = $decklistManager->findDecklistsByPopularity();
                break;
        }

        $pagetitle = $translator->trans('decklist.list.titles.'.$type);

        return $this->render(
            'Decklist/decklists.html.twig',
            array(
                'pagetitle' => $pagetitle,
                'pagedescription' => "Browse the collection of thousands of premade decks.",
                'decklists' => $paginator,
                'url' => $request->getRequestUri(),
                'header' => $header,
                'type' => $type,
                'pages' => $decklistManager->getClosePages(),
                'prevurl' => $decklistManager->getPreviousUrl(),
                'nexturl' => $decklistManager->getNextUrl(),
            ),
            $response
        );
    }

    /**
     * Displays the content of a decklist along with comments, siblings, similar, etc.
     *
     * @Route(
     *     "/decklist/view/{decklist_id}/{decklist_name}",
     *     name="decklist_detail",
     *     methods={"GET"},
     *     defaults={"decklist_name"=null},
     *     requirements={"decklist_id"="\d+"}
     * )
     *
     * @param TranslatorInterface $translator
     * @param int $cacheExpiration
     * @param string $decklist_id
     * @return Response
     */
    public function viewAction(TranslatorInterface $translator, int $cacheExpiration, $decklist_id)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($cacheExpiration);

        $decklistRepo = $this->getDoctrine()->getManager()->getRepository(Decklist::class);

        $decklist = $decklistRepo->find($decklist_id);
        if (!$decklist) {
            throw $this->createNotFoundException($translator->trans('decklist.view.errors.notfound'));
        }

        $duplicate = $decklistRepo->findDuplicate($decklist);
        if ($duplicate->getDateCreation() >= $decklist->getDateCreation()
            || $duplicate->getId() === $decklist->getId()) {
            $duplicate = null;
        }

        $commenters = array_map(
            function (CommentInterface $comment) {
                return $comment->getUser()->getUsername();
            },
            $decklist->getComments()->getValues()
        );

        $versions = $decklistRepo->findVersions($decklist);

        return $this->render(
            'Decklist/decklist.html.twig',
            array(
                'pagetitle' => $decklist->getName(),
                'decklist' => $decklist,
                'duplicate' => $duplicate,
                'commenters' => $commenters,
                'versions' => $versions,
            ),
            $response
        );
    }

    /**
     * Adds a decklist to a user's list of favorites.
     *
     * @Route("/user/favorite", name="decklist_favorite", methods={"POST"})
     *
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function favoriteAction(Request $request)
    {
        /* @var $em EntityManager */
        $em = $this->getDoctrine()->getManager();

        $user = $this->getUser();
        if (!$user) {
            throw new UnauthorizedHttpException('You must be logged in to comment.');
        }

        $decklist_id = filter_var($request->get('id'), FILTER_SANITIZE_NUMBER_INT);

        /* @var DecklistInterface $decklist */
        $decklist = $em->getRepository(Decklist::class)->find($decklist_id);
        if (!$decklist) {
            throw new NotFoundHttpException('Wrong id');
        }

        $author = $decklist->getUser();

        $dbh = $this->getDoctrine()->getConnection();
        $is_favorite = $dbh->executeQuery(
            "SELECT
                count(*)
                from decklist d
                join favorite f on f.decklist_id=d.id
                where f.user_id=?
                and d.id=?",
            array(
                $user->getId(),
                $decklist_id,
            )
        )
            ->fetch(PDO::FETCH_NUM)[0];

        if ($is_favorite) {
            $decklist->setNbfavorites($decklist->getNbFavorites() - 1);
            $user->removeFavorite($decklist);
            if ($author->getId() != $user->getId()) {
                $author->setReputation($author->getReputation() - 5);
            }
        } else {
            $decklist->setNbfavorites($decklist->getNbFavorites() + 1);
            $user->addFavorite($decklist);
            $decklist->setDateUpdate(new DateTime());
            if ($author->getId() != $user->getId()) {
                $author->setReputation($author->getReputation() + 5);
            }
        }
        $this->getDoctrine()->getManager()->flush();

        return new Response($decklist->getNbFavorites());
    }

    /**
     * Records a user's comment.
     *
     * @Route("/user/comment", name="decklist_comment", methods={"POST"})
     *
     * @param Request $request
     * @param Texts $texts
     * @param Swift_Mailer $mailer
     * @param string $emailSenderAddress
     * @return RedirectResponse
     */
    public function commentAction(
        Request $request,
        Texts $texts,
        Swift_Mailer $mailer,
        string $emailSenderAddress
    ) {
        /* @var UserInterface $user */
        $user = $this->getUser();
        if (!$user) {
            throw new UnauthorizedHttpException('You must be logged in to comment.');
        }

        $decklist_id = filter_var($request->get('id'), FILTER_SANITIZE_NUMBER_INT);
        $decklist = $this->getDoctrine()
            ->getRepository(Decklist::class)
            ->find($decklist_id);

        $comment_text = trim($request->get('comment'));
        if ($decklist && !empty($comment_text)) {
            $comment_text = preg_replace(
                '%(?<!\()\b(?:(?:https?|ftp)://)(?:((?:(?:[a-z\d\x{00a1}-\x{ffff}]+-?)*[a-z\d\x{00a1}-\x{ffff}]+)'
                .'(?:\.(?:[a-z\d\x{00a1}-\x{ffff}]+-?)*[a-z\d\x{00a1}-\x{ffff}]+)*(?:\.[a-z\x{00a1}-\x{ffff}]{2,6}))'
                .'(?::\d+)?)(?:[^\s]*)?%iu',
                '[$1]($0)',
                $comment_text
            );

            $mentioned_usernames = [];
            $matches = [];
            if (preg_match_all('/`@([\w_]+)`/', $comment_text, $matches, PREG_PATTERN_ORDER)) {
                $mentioned_usernames = array_unique($matches[1]);
            }

            $comment_html = $texts->markdown($comment_text);

            $now = new DateTime();

            $comment = new Comment();
            $comment->setText($comment_html);
            $comment->setDateCreation($now);
            $comment->setUser($user);
            $comment->setDecklist($decklist);
            $comment->setIsHidden(false);

            $this->getDoctrine()
                ->getManager()
                ->persist($comment);
            $decklist->setDateUpdate($now);
            $decklist->setNbcomments($decklist->getNbcomments() + 1);

            $this->getDoctrine()
                ->getManager()
                ->flush();

            // send emails
            $spool = [];
            if ($decklist->getUser()->getIsNotifAuthor()) {
                if (!isset($spool[$decklist->getUser()->getEmail()])) {
                    $spool[$decklist->getUser()->getEmail()] = 'Emails/newcomment_author.html.twig';
                }
            }
            foreach ($decklist->getComments() as $comment) {
                /* @var CommentInterface $comment */
                $commenter = $comment->getUser();
                if ($commenter && $commenter->getIsNotifCommenter()) {
                    if (!isset($spool[$commenter->getEmail()])) {
                        $spool[$commenter->getEmail()] = 'Emails/newcomment_commenter.html.twig';
                    }
                }
            }
            foreach ($mentioned_usernames as $mentioned_username) {
                /* @var UserInterface $mentioned_user */
                $mentioned_user = $this->getDoctrine()
                    ->getRepository(User::class)
                    ->findOneBy(array('username' => $mentioned_username));
                if ($mentioned_user && $mentioned_user->getIsNotifMention()) {
                    if (!isset($spool[$mentioned_user->getEmail()])) {
                        $spool[$mentioned_user->getEmail()] = 'Emails/newcomment_mentionned.html.twig';
                    }
                }
            }
            unset($spool[$user->getEmail()]);

            $email_data = array(
                'username' => $user->getUsername(),
                'decklist_name' => $decklist->getName(),
                'url' => $this->generateUrl(
                    'decklist_detail',
                    array(
                            'decklist_id' => $decklist->getId(),
                            'decklist_name' => $decklist->getNameCanonical(),
                        ),
                    UrlGeneratorInterface::ABSOLUTE_URL
                ).'#'.$comment->getId(),
                'comment' => $comment_html,
                'profile' => $this->generateUrl('user_profile_edit', [], UrlGeneratorInterface::ABSOLUTE_URL),
            );
            foreach ($spool as $email => $view) {
                $message = (new Swift_Message("[thronesdb] New comment"))
                    ->setFrom(array($emailSenderAddress => $user->getUsername()))
                    ->setTo($email)
                    ->setBody($this->renderView($view, $email_data), 'text/html');
                $mailer->send($message);
            }
        }

        return $this->redirect(
            $this->generateUrl(
                'decklist_detail',
                array(
                    'decklist_id' => $decklist_id,
                    'decklist_name' => $decklist->getNameCanonical(),
                )
            )
        );
    }

    /**
     * Hides a comment, or if $hidden is false, unhide a comment.
     *
     * @Route("/user/hidecomment/{comment_id}/{hidden}", name="decklist_comment_hide", methods={"POST"})
     *
     * @param string $comment_id
     * @param string $hidden
     * @return Response
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function hidecommentAction($comment_id, $hidden)
    {
        /* @var UserInterface $user */
        $user = $this->getUser();
        if (!$user) {
            throw new UnauthorizedHttpException('You must be logged in to comment.');
        }

        /* @var $em EntityManager */
        $em = $this->getDoctrine()->getManager();

        $comment = $em->getRepository(Comment::class)->find($comment_id);
        if (!$comment) {
            throw new BadRequestHttpException('Unable to find comment');
        }

        if ($comment->getDecklist()->getUser()->getId() !== $user->getId()) {
            return new Response(json_encode("You don't have permission to edit this comment."));
        }

        $comment->setIsHidden((boolean)$hidden);
        $em->flush();

        return new Response(json_encode(true));
    }

    /**
     * Records a user's vote.
     *
     * @Route("/user/like", name="decklist_like", methods={"POST"})
     *
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function voteAction(Request $request)
    {
        /* @var EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        /* @var UserInterface $user */
        $user = $this->getUser();
        if (!$user) {
            throw new UnauthorizedHttpException('You must be logged in to comment.');
        }

        $decklist_id = filter_var($request->get('id'), FILTER_SANITIZE_NUMBER_INT);

        $decklist = $em->getRepository(Decklist::class)->find($decklist_id);

        if ($decklist->getUser()->getId() != $user->getId()) {
            $query = $em->getRepository(Decklist::class)
                ->createQueryBuilder('d')
                ->innerJoin('d.votes', 'u')
                ->where('d.id = :decklist_id')
                ->andWhere('u.id = :user_id')
                ->setParameter('decklist_id', $decklist_id)
                ->setParameter('user_id', $user->getId())
                ->getQuery();

            $result = $query->getResult();
            if (empty($result)) {
                $user->addVote($decklist);
                $author = $decklist->getUser();
                $author->setReputation($author->getReputation() + 1);
                $decklist->setDateUpdate(new DateTime());
                $decklist->setNbVotes($decklist->getNbVotes() + 1);
                $this->getDoctrine()->getManager()->flush();
            } else {
                $user->removeVote($decklist);
                $author = $decklist->getUser();
                $author->setReputation($author->getReputation() - 1);
                $decklist->setDateUpdate(new DateTime());
                $decklist->setNbVotes($decklist->getNbVotes() - 1);
                $this->getDoctrine()->getManager()->flush();
            }
        }

        return new Response($decklist->getNbVotes());
    }

    /**
     * (Unused) returns an ordered list of decklists similar to the one given.
     * @todo This is not wired up, enable or remove this. [ST 2020/07/28]
     */
    public function findSimilarDecklists($decklist_id, $number)
    {
        $dbh = $this->getDoctrine()->getConnection();

        $list = $dbh->executeQuery(
            "SELECT
                l.id,
                (
                    SELECT COUNT(s.id)
                    FROM decklistslot s
                    WHERE (
                        s.decklist_id=l.id
                        AND s.card_id NOT IN (
                            SELECT t.card_id
                            FROM decklistslot t
                            WHERE t.decklist_id=?
                        )
                    )
                    OR
                    (
                        s.decklist_id=?
                        AND s.card_id NOT IN (
                            SELECT t.card_id
                            FROM decklistslot t
                            WHERE t.decklist_id=l.id
                        )
                    )
                ) difference
                 FROM decklist l
                WHERE l.id!=?
                ORDER BY difference ASC
                LIMIT 0,$number",
            array(
                $decklist_id,
                $decklist_id,
                $decklist_id,
            )
        )->fetchAll();

        $arr = [];
        foreach ($list as $item) {
            $dbh = $this->getDoctrine()->getConnection();
            $rows = $dbh->executeQuery(
                "SELECT
                    d.id,
                    d.name,
                    d.name_canonical,
                    d.nb_votes,
                    d.nb_favorites,
                    d.nb_comments
                    from decklist d
                    where d.id=?
                    ",
                array(
                    $item["id"],
                )
            )->fetchAll();

            $decklist = $rows[0];
            $arr[] = $decklist;
        }

        return $arr;
    }

    /**
     * @Route(
     *     "/decklist/export/text/{decklist_id}",
     *     name="decklist_download",
     *     methods={"GET"},
     *     requirements={"decklist_id"="\d+"}
     * )
     *
     * @param Request $request
     * @param int $cacheExpiration
     * @param Texts $texts
     * @param int $decklist_id
     * @return Response
     */
    public function downloadAction(Request $request, int $cacheExpiration, Texts $texts, $decklist_id)
    {
        /* @var EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        /* @var DecklistInterface $decklist */
        $decklist = $em->getRepository(Decklist::class)->find($decklist_id);
        if (!$decklist) {
            throw new NotFoundHttpException("Unable to find decklist.");
        }

        $format = $request->query->get('format', 'text');

        switch ($format) {
            case 'octgn':
                return $this->downloadInOctgnFormat($cacheExpiration, $texts, $decklist);
                break;
            case 'text_cycle':
                return $this->downloadInTextFormatSortedByCycle($cacheExpiration, $texts, $decklist);
                break;
            case 'text':
            default:
                return $this->downloadInDefaultTextFormat($cacheExpiration, $texts, $decklist);
        }
    }

    /**
     * @param int $cacheExpiration
     * @param Texts $texts
     * @param DecklistInterface $decklist
     * @return Response
     */
    protected function downloadInOctgnFormat(int $cacheExpiration, Texts $texts, DecklistInterface $decklist)
    {
        $content = $this->renderView('Export/octgn.xml.twig', [ "deck" => $decklist->getTextExport() ]);

        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($cacheExpiration);
        $response->headers->set('Content-Type', 'application/octgn');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $texts->slugify($decklist->getName()).'.o8d'
            )
        );

        $response->setContent($content);

        return $response;
    }

    /**
     * @param int $cacheExpiration
     * @param Texts $texts
     * @param DecklistInterface $decklist
     * @return Response
     */
    protected function downloadInDefaultTextFormat(int $cacheExpiration, Texts $texts, DecklistInterface $decklist)
    {
        $content = $this->renderView(
            'Export/default.txt.twig',
            [
                "deck" => $decklist->getTextExport(),
            ]
        );
        $content = str_replace("\n", "\r\n", $content);

        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($cacheExpiration);
        $response->headers->set('Content-Type', 'text/plain');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $texts->slugify($decklist->getName()).'.txt'
            )
        );

        $response->setContent($content);
        return $response;
    }

    /**
     * @param int $cacheExpiration
     * @param Texts $texts
     * @param DecklistInterface $decklist
     * @return Response
     */
    protected function downloadInTextFormatSortedByCycle(
        int $cacheExpiration,
        Texts $texts,
        DecklistInterface $decklist
    ) {
        $content = $this->renderView(
            'Export/sortedbycycle.txt.twig',
            [
                "deck" => $decklist->getCycleOrderExport(),
            ]
        );
        $content = str_replace("\n", "\r\n", $content);

        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($cacheExpiration);
        $response->headers->set('Content-Type', 'text/plain');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $texts->slugify($decklist->getName()).'.txt'
            )
        );

        $response->setContent($content);

        return $response;
    }

    /**
     * @param $page
     * @param Request $request
     * @return Response
     * @throws DBALException
     *
     * @todo This is not wired up, enable or remove this. [ST 2020/07/28]
     */
    public function usercommentsAction($page, Request $request)
    {
        $response = new Response();
        $response->setPrivate();

        /* @var UserInterface $user */
        $user = $this->getUser();

        $limit = 100;
        if ($page < 1) {
            $page = 1;
        }
        $start = ($page - 1) * $limit;

        /* @var $dbh Connection */
        $dbh = $this->getDoctrine()->getConnection();

        $comments = $dbh->executeQuery(
            "SELECT SQL_CALC_FOUND_ROWS
                c.id,
                c.text,
                c.date_creation,
                d.id decklist_id,
                d.name decklist_name,
                d.name_canonical decklist_name_canonical
                from comment c
                join decklist d on c.decklist_id=d.id
                where c.user_id=?
                order by date_creation desc
                limit $start, $limit",
            array(
                $user->getId(),
            )
        )
            ->fetchAll(PDO::FETCH_ASSOC);

        $maxcount = $dbh->executeQuery("SELECT FOUND_ROWS()")->fetch(PDO::FETCH_NUM)[0];

        // pagination : calcul de nbpages // currpage // prevpage // nextpage
        // à partir de $start, $limit, $count, $maxcount, $page

        $currpage = $page;
        $prevpage = max(1, $currpage - 1);
        $nbpages = min(10, ceil($maxcount / $limit));
        $nextpage = min($nbpages, $currpage + 1);

        $route = $request->get('_route');

        $pages = [];
        for ($page = 1; $page <= $nbpages; $page++) {
            $pages[] = array(
                "numero" => $page,
                "url" => $this->generateUrl(
                    $route,
                    array(
                        "page" => $page,
                    )
                ),
                "current" => $page == $currpage,
            );
        }

        return $this->render(
            'Default/usercomments.html.twig',
            array(
                'user' => $user,
                'comments' => $comments,
                'url' => $request
                    ->getRequestUri(),
                'route' => $route,
                'pages' => $pages,
                'prevurl' => $currpage == 1 ? null : $this->generateUrl(
                    $route,
                    array(
                        "page" => $prevpage,
                    )
                ),
                'nexturl' => $currpage == $nbpages ? null : $this->generateUrl(
                    $route,
                    array(
                        "page" => $nextpage,
                    )
                ),
            ),
            $response
        );
    }

    /**
     * @param Request $request
     * @param int $cacheExpiration
     * @param int $page
     * @return Response
     * @throws DBALException
     *
     * @todo This is not wired up, enable or remove this. [ST 2020/07/28]
     */
    public function commentsAction(Request $request, int $cacheExpiration, $page)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($cacheExpiration);

        $limit = 100;
        if ($page < 1) {
            $page = 1;
        }
        $start = ($page - 1) * $limit;

        /* @var $dbh Connection */
        $dbh = $this->getDoctrine()->getConnection();

        $comments = $dbh->executeQuery(
            "SELECT SQL_CALC_FOUND_ROWS
                c.id,
                c.text,
                c.date_creation,
                d.id decklist_id,
                d.name decklist_name,
                d.name_canonical decklist_name_canonical,
                u.id user_id,
                u.username author
                from comment c
                join decklist d on c.decklist_id=d.id
                join user u on c.user_id=u.id
                order by date_creation desc
                limit $start, $limit",
            []
        )->fetchAll(PDO::FETCH_ASSOC);

        $maxcount = $dbh->executeQuery("SELECT FOUND_ROWS()")->fetch(PDO::FETCH_NUM)[0];

        // pagination : calcul de nbpages // currpage // prevpage // nextpage
        // à partir de $start, $limit, $count, $maxcount, $page
        $currpage = $page;
        $prevpage = max(1, $currpage - 1);
        $nbpages = min(10, ceil($maxcount / $limit));
        $nextpage = min($nbpages, $currpage + 1);

        $route = $request->get('_route');

        $pages = [];
        for ($page = 1; $page <= $nbpages; $page++) {
            $pages[] = array(
                "numero" => $page,
                "url" => $this->generateUrl(
                    $route,
                    array(
                        "page" => $page,
                    )
                ),
                "current" => $page == $currpage,
            );
        }

        return $this->render(
            'Default/allcomments.html.twig',
            array(
                'comments' => $comments,
                'url' => $request
                    ->getRequestUri(),
                'route' => $route,
                'pages' => $pages,
                'prevurl' => $currpage == 1 ? null : $this->generateUrl(
                    $route,
                    array(
                        "page" => $prevpage,
                    )
                ),
                'nexturl' => $currpage == $nbpages ? null : $this->generateUrl(
                    $route,
                    array(
                        "page" => $nextpage,
                    )
                ),
            ),
            $response
        );
    }

    /**
     * @Route("/decklists/search", name="decklists_searchform", methods={"GET"})
     *
     * @param Request $request
     * @param TranslatorInterface $translator
     * @param int $cacheExpiration
     * @return Response
     */
    public function searchAction(Request $request, TranslatorInterface $translator, int $cacheExpiration)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($cacheExpiration);

        $factions = $this->getDoctrine()->getRepository(Faction::class)->findAllAndOrderByName();

        $categories = [];
        $on = 0;
        $off = 0;
        $categories[] = array("label" => $translator->trans("decklist.list.search.allowed.core"), "packs" => []);
        $list_cycles = $this->getDoctrine()->getRepository(Cycle::class)->findAll();
        foreach ($list_cycles as $cycle) {
            $size = count($cycle->getPacks());
            if ($cycle->getPosition() == 0 || $size == 0) {
                continue;
            }
            $first_pack = $cycle->getPacks()[0];
            if ($cycle->getCode() === 'core' || ($size === 1 && $first_pack->getName() == $cycle->getName())) {
                $checked = $first_pack->getDateRelease() !== null;
                if ($checked) {
                    $on++;
                } else {
                    $off++;
                }
                $categories[0]["packs"][] = array(
                    "id" => $first_pack->getId(),
                    "label" => $first_pack->getName(),
                    "checked" => $checked,
                    "future" => $first_pack->getDateRelease() === null,
                );
            } else {
                $category = array("label" => $cycle->getName(), "packs" => []);
                foreach ($cycle->getPacks() as $pack) {
                    $checked = $pack->getDateRelease() !== null;
                    if ($checked) {
                        $on++;
                    } else {
                        $off++;
                    }
                    $category['packs'][] = array(
                        "id" => $pack->getId(),
                        "label" => $pack->getName(),
                        "checked" => $checked,
                        "future" => $pack->getDateRelease() === null,
                    );
                }
                $categories[] = $category;
            }
        }

        $activeTournamentTiers = $this->getDoctrine()
            ->getRepository(Tournament::class)
            ->findBy(['active' => true]);

        $inactiveTournamentTiers = $this->getDoctrine()
            ->getRepository(Tournament::class)
            ->findBy(['active' => false]);

        $searchForm = $this->renderView(
            'Search/form.html.twig',
            array(
                'factions' => $factions,
                'allowed' => $categories,
                'on' => $on,
                'off' => $off,
                'author' => '',
                'name' => '',
                'activeTournamentTiers' => $activeTournamentTiers,
                'inactiveTournamentTiers' => $inactiveTournamentTiers,
                'selectedTournament' => 0,
            )
        );

        return $this->render(
            'Decklist/decklists.html.twig',
            array(
                'pagetitle' => $translator->trans('decklist.list.titles.search'),
                'decklists' => null,
                'url' => $request->getRequestUri(),
                'header' => $searchForm,
                'type' => 'find',
                'pages' => null,
                'prevurl' => null,
                'nexturl' => null,
            ),
            $response
        );
    }

    /**
     * @Route("/donators", name="donators", methods={"GET"})
     * @param Request $request
     * @param int $cacheExpiration
     * @return Response
     * @throws DBALException
     */
    public function donatorsAction(Request $request, int $cacheExpiration)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($cacheExpiration);

        /* @var $dbh Connection */
        $dbh = $this->getDoctrine()->getConnection();

        $users = $dbh->executeQuery("SELECT * FROM user WHERE donation>0 ORDER BY donation DESC, username", [])
            ->fetchAll(PDO::FETCH_ASSOC);

        return $this->render(
            $this->getLocaleSpecificViewPath(
                'donators',
                $request->getLocale()
            ),
            array(
                'pagetitle' => 'The Gracious Donators',
                'donators' => $users,
            ),
            $response
        );
    }
}
