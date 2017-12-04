<?php

final class PhabricatorPeopleProfileTimeController
  extends PhabricatorPeopleProfileController {

  public function shouldAllowPublic() {
    return true;
  }

  public function handleRequest(AphrontRequest $request) {
    $viewer = $this->getViewer();
    $id = $request->getURIData('id');

    $user = id(new PhabricatorPeopleQuery())
      ->setViewer($viewer)
      ->withIDs(array($id))
      ->needProfile(true)
      ->needUserSettings(true)
      ->needProfileImage(true)
      ->needAvailability(true)
      ->executeOne();
    if (!$user) {
      return new Aphront404Response();
    }

    $class = 'PhabricatorPhrequentApplication';
    if (!PhabricatorApplication::isClassInstalledForViewer($class, $viewer)) {
      return new Aphront404Response();
    }

    $this->setUser($user);
    $title = array(pht('Time logging'), $user->getUsername());
    $header = $this->buildProfileHeader();
    $timelogs = $this->buildTimeLogView($user);

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb(pht('Recent timelogs'));
    $crumbs->setBorder(true);

    $navigation = $this->newNavigation(
      $user,
      PhabricatorPeopleProfileMenuEngine::ITEM_TIME);

    $view = id(new PHUITwoColumnView())
      ->setHeader($header)
      ->addClass('project-view-home')
      ->addClass('project-view-people-home')
      ->setFooter(array(
        $timelogs,
      ));

    return $this->newPage()
      ->setTitle($title)
      ->setCrumbs($crumbs)
      ->setNavigation($navigation)
      ->appendChild($view);
  }

  private function buildTimeLogView(PhabricatorUser $user) {
    
    $request = id(new PhrequentUserTimeQuery())
    ->withUserPHIDs(array($user->getPHID()))
    ->setLimit(100)
    ->setViewer($user)
    ->needPreemptingEvents(true);

    $usertimes = $request->execute();

      $view = id(new PHUIObjectItemListView())
      ->setUser($user);

    $handles = array();
    foreach ($usertimes as $usertime) {
      $item = new PHUIObjectItemView();
      if ($usertime->getObjectPHID() === null) {
        $item->setHeader($usertime->getNote());
      } 
      $item->setObject($usertime);

      $block = new PhrequentTimeBlock(array($usertime));
      $time_spent = $block->getTimeSpentOnObject(
        $usertime->getObjectPHID(),
        PhabricatorTime::getNow());

      $time_spent = $time_spent == 0 ? 'none' :
        phutil_format_relative_time_detailed($time_spent);

      if ($usertime->getDateEnded() !== null) {
        $item->addAttribute(
          pht(
            'Tracked %s',
            $time_spent));
        $item->addAttribute(
          pht(
            'Started on %s',
            phabricator_datetime($usertime->getDateStarted(), $user)));

        $item->addAttribute(
          pht(
            'Ended on %s',
            phabricator_datetime($usertime->getDateEnded(), $user)));

        if ($usertime->getObjectPHID() !== null &&
          $usertime->getUserPHID() === $user->getPHID()) {
          $back_uri = '/';
          if ($this->getRequest() !== null) {
            $back_uri = $this->getRequest()->GetPath();
          }
          $uri = new PhutilURI('/phrequent/track/delete/'.
              $usertime->getObjectPHID().'/');
          $parameters = array();
          $parameters['__back__'] = $back_uri;
          $parameters['__timelog_id__'] = $usertime->getID();
          $uri->setQueryParams($parameters);
          $href = $uri->__toString();

          $item->addAction(
              id(new PHUIListItemView())
                ->setIcon('fa-trash')
                ->addSigil('phrequent-delete-worklog')
                ->setWorkflow(true)
                ->setRenderNameAsTooltip(true)
                ->setName(pht('Delete'))
                ->setHref($href));
        }

      } else {
        $item->addAttribute(
          pht(
            'Tracked %s so far',
            $time_spent));
        if ($usertime->getObjectPHID() !== null &&
            $usertime->getUserPHID() === $viewer->getPHID()) {
          $item->addAction(
            id(new PHUIListItemView())
              ->setIcon('fa-stop')
              ->addSigil('phrequent-stop-tracking')
              ->setWorkflow(true)
              ->setRenderNameAsTooltip(true)
              ->setName(pht('Stop'))
              ->setHref(
                '/phrequent/track/stop/'.
                $usertime->getObjectPHID().'/'));
        }
        $item->setStatusIcon('fa-clock-o green');
      }

      $view->addItem($item);
    }
    return $view;

  }
}
