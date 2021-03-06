<?php

namespace Nodeart\BuilderBundle\Controller;

use Nodeart\BuilderBundle\Controller\Base\BaseController;
use Nodeart\BuilderBundle\Entity\CommentNode;
use Nodeart\BuilderBundle\Entity\Repositories\CommentNodeRepository;
use Nodeart\BuilderBundle\Entity\UserCommentReaction;
use Nodeart\BuilderBundle\Entity\UserNode;
use Nodeart\BuilderBundle\Form\CommentNodeType;
use Nodeart\BuilderBundle\Helpers\CommentSaver;
use Nodeart\BuilderBundle\Services\Pager\Pager;
use Nodeart\BuilderBundle\Services\Pager\Queries\CommentsQueries;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CommentsController extends BaseController
{

    const COMMENT_REACTION_UPDOOD = '+';
    const COMMENT_REACTION_DOWNDOOD = '-';
    const COMMENT_REACTION_WHINE = 'boo';

    const COMMENT_MAX_WHINES = 5;

    //	frond part below

    /**
     * @Route("/comments/add", name="comments_add")
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function addCommentAction(Request $request)
    {

        $nm = $this->get('neo.app.manager');
        $user = $this->getUser();

        $comment = new CommentNode();
        $formBuilder = $this->get('form.factory')->createNamedBuilder('comment_form', CommentNodeType::class, $comment);
        $form = $formBuilder->add('submit_button', SubmitType::class, ['label' => 'Создать объект'])->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted()) {

            if (empty(trim($form->get('comment')->getData()))) {
                $form->get('comment')->addError(new FormError('Comment should not be empty'));
            }

            // standart form validation
            if ($form->isValid()) {
                $this->get(CommentSaver::class)
                    ->bindForm($form)
                    ->bindComment($comment)
                    ->processForm();

                if (
                    $user->isApproved()
                    || ($this->getParameter('neo4j_builder_comments_only_approved') == false)
                    || $user->hasRole(UserNode::ROLE_ADMIN)
                    || $user->hasRole(UserNode::ROLE_CONTENT_MANAGER)
                ) {
                    $comment->setStatus(CommentNode::STATUS_APPROVED);
                }

                $comment->setAuthor($user);
                $nm->persist($comment);
                $nm->flush();
            }

            // in case if ajax: if valid - return single comment template, else return json with error
            if ($request->isXmlHttpRequest()) {
                if ($form->isValid()) {
                    return $this->render('BuilderBundle:Comments:single.comment.html.twig', [
                        'pair' => ['comment' => $comment, 'user' => $user],
                        'userReactions' => self::EMPTY_USER_REACTIONS
                    ]);
                } else {
                    $errorMessages = [];
                    foreach ($form->getErrors(true) as $formError) {
                        $errorMessages[] = $formError->getMessage();
                    }
                    return new JsonResponse([
                        'status' => 'failure',
                        'message' => 'Comment form has errors',
                        'errors' => $errorMessages
                    ], Response::HTTP_BAD_REQUEST);
                }
            }
        }

        return $this->render('default/empty.form.html.twig', [
            'form' => $form->createView(),
            'userReactions' => self::EMPTY_USER_REACTIONS
        ]);
    }

    /**
     * @Route("/comments/paged/{oId}/{type}/page/{page}/{perPage}", name="comments_paged_list", requirements={"oId": "\d+", "page": "\d+", "perPage": "\d+"}, defaults={"page":1})
     * @param int $oId
     * @param $type string
     * @param int $page
     * @param int $perPage
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function listPagedCommentsAction(int $oId, string $type, int $page, int $perPage = 10)
    {
        $nm = $this->get('neo.app.manager');

        /** @var Pager $pager */
        $pager = $this->get('neo.pager');
        $pager->setLimit($perPage);

        $pagerQueries = new CommentsQueries($nm);
        $pagerQueries->setParams(['oId' => $oId, 'type' => $type]);

        $pager->createQueries($pagerQueries);

        $userReactions = self::EMPTY_USER_REACTIONS;
        if ($user = $this->getUser()) {
            /** @var CommentNodeRepository $repo */
            $repo = $nm->getRepository(CommentNode::class);
            $userReactions = $repo->findObjectUserReactions($oId, $user->getId());
        }

        $masterRequest = $this->get('request_stack')->getMasterRequest();

        $response = $this->render('BuilderBundle:Comments:paged.list.comments.html.twig', [
            'oId' => $oId,
            'type' => $type,
            'comments' => $pager->paginate(),
            'pager' => $pager->getPaginationData(),
            'userReactions' => $userReactions,
            'masterRoute' => $masterRequest->attributes->get('_route'),
            'masterParams' => $masterRequest->attributes->get('_route_params')
        ]);
        $response->setSharedMaxAge(120);

        return $response;
    }

    /**
     * @Route("/comments/more/{fromId}/second/{oId}", name="comments_load_more_childs", requirements={"fromId": "\d+", "oId": "\d+"})
     * @param int $fromId
     * @param int $oId
     *
     * @return Response
     */
    public function loadMoreChildCommentsAction(int $fromId, int $oId)
    {
        $nm = $this->get('neo.app.manager');
        /** @var CommentNodeRepository $repo */
        $repo = $nm->getRepository(CommentNode::class);

        $comments = $repo->findMoreChildComments($fromId);
        if (count($comments['comments']) == 0) {
            return new JsonResponse([
                'status' => 'not_found',
                'message' => 'No more comments for you!',
            ], Response::HTTP_NOT_FOUND);
        }

        $userReactions = self::EMPTY_USER_REACTIONS;
        if ($user = $this->getUser()) {
            /** @var CommentNodeRepository $repo */
            $repo = $nm->getRepository(CommentNode::class);
            $userReactions = $repo->findObjectUserReactions($oId, $user->getId());
        }

        $response = $this->render('@Builder/Comments/flat.childs.list.comments.html.twig', [
            'comments' => $comments['comments'],
            'userReactions' => $userReactions,
            'oId' => $oId
        ]);
        $response->setSharedMaxAge(120);

        return $response;
    }


    /**
     * @Route("/comments/updood/{id}/{action}", name="comments_updood", requirements={"id": "\d+"}, defaults={"action":"+"})
     * @param int $id
     * @param string $action
     *
     * @return JsonResponse
     */
    public function updoodCommentAction(int $id, string $action)
    {
        $response = new JsonResponse();
        $possibleActions = [
            self::COMMENT_REACTION_UPDOOD,
            self::COMMENT_REACTION_DOWNDOOD,
            self::COMMENT_REACTION_WHINE
        ];

        if (!in_array($action, $possibleActions)) {
            $response->setData([
                'status' => 'bad_action',
                'message' => 'Second url param must one of the following: "' . implode('", "', $possibleActions) . '"',
            ]);
            $response->setStatusCode(Response::HTTP_BAD_REQUEST);

            return $response;
        }

        $nm = $this->get('neo.app.manager');
        /** @var CommentNodeRepository $repo */
        $repo = $nm->getRepository(CommentNode::class);
        /** @var CommentNode $comment */
        $comment = $repo->findOneById($id);

        if (!$comment) {
            $response->setData([
                'status' => 'not_found',
                'message' => 'Comment not found',
            ]);
            $response->setStatusCode(Response::HTTP_NOT_FOUND);

            return $response;
        }
        $user = $this->getUser();

        // find user reaction, if not found - create new empty one
        $userReaction = $comment->getReactionByUser($user);
        if (is_null($userReaction)) {
            $userReaction = new UserCommentReaction($user, $comment);
            $user->getReactions()->add($userReaction);
            $comment->getReactions()->add($userReaction);
        }

        $changedValues = ['like' => 0, 'dislike' => 0, 'report' => 0];
        switch ($action) {
            case self::COMMENT_REACTION_UPDOOD:
                {
                    $userReaction->setLiked(!$userReaction->isLiked());
                    $changedValues['like'] = $userReaction->isLiked() ? +1 : -1;
                    $comment->changeLikes($changedValues['like']);
                    // if just liked - remove dislike
                    if ($userReaction->isDisliked() && $userReaction->isLiked()) {
                        $changedValues['dislike'] = -1;
                        $userReaction->setDisliked(false);
                        $comment->changeDislikes($changedValues['dislike']);
                    }
                    break;
                }
            case self::COMMENT_REACTION_DOWNDOOD:
                {
                    $userReaction->setDisliked(!$userReaction->isDisliked());
                    $changedValues['dislike'] = $userReaction->isdisliked() ? +1 : -1;
                    $comment->changeDislikes($changedValues['dislike']);

                    // if just disliked - remove like
                    if ($userReaction->isDisliked() && $userReaction->isLiked()) {
                        $changedValues['like'] = -1;
                        $userReaction->setLiked(false);
                        $comment->changeLikes($changedValues['like']);
                    }
                    break;
                }
            case self::COMMENT_REACTION_WHINE:
                {
                    $userReaction->setWhined(!$userReaction->isWhined());
                    $changedValues['report'] = $userReaction->isWhined() ? +1 : -1;
                    $comment->changeReports($changedValues['report']);
                    if ($comment->getReports() > self::COMMENT_MAX_WHINES) {
                        $comment->setStatus(CommentNode::STATUS_SPAM);
                    }
                    break;
                }
        }
        $nm->flush();

        $response->setData([
            'status' => 'updated',
            'action' => $action,
            'events' => $changedValues,
            'updatedValues' => [
                'likes' => $comment->getLikes(),
                'dislikes' => $comment->getDislikes(),
                'reports' => $comment->getReports()
            ],
        ]);

        return $response;
    }

}