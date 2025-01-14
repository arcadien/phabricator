<?php

 final class PhrequentTrackController extends PhrequentController {

  private $verb;
  private $phid;

  public function willProcessRequest (array $data)
  {
    $this->phid = $data['phid'];
    $this->verb = $data['verb'];
  }

  public function processRequest ()
  {
    $request = $this->getRequest ();
    $viewer = $request->getUser ();


    $phid = $this->phid;
    $handle =
      id (new PhabricatorHandleQuery ())->setViewer ($viewer)->
      withPHIDs (array ($phid))->executeOne ();
    $done_uri = $handle->getURI ();

    $current_timer = null;
    switch ($this->verb)
      {
      case 'start':
        $button_text = pht ('Start Tracking');
        $title_text = pht ('Start Tracking Time');
        $inner_text = pht ('What time did you start working?');
        $ok_button_text = pht ('Start Timer');
        $label_text = pht ('Start Time');
        break;

      case 'worklog':
        $button_text = pht ('Add Worklog');
        $title_text = pht ('Add Worklog');
        $inner_text = pht ('When did you start ');
        $inner_text .= pht('and how long did you worked on current item?');
        // $inner_text .= pht('You can log weeks, ').
        //    pht('days, hours and minutes using');
        // $inner_text .= pht(' one digit and one letter for each').
        //      pth((, ex. 1w2d5h30m');
        $inner_text .= pht('You can log hours and minutes using');
        $inner_text .= pht(' one digit and one letter for each, ex. 5h30m');
        $ok_button_text = pht ('Add worklog');
        $label_text = pht ('Start Time');
        $worklog_action_text = pht ('Worklog');
        break;

      case 'stop':
        $button_text = pht ('Stop Tracking');
        $title_text = pht ('Stop Tracking Time');
        $inner_text = pht ('What time did you stop working?');
        $ok_button_text = pht ('Stop timer');
        $label_text = pht ('Stop Time');


        $current_timer =
          id (new PhrequentUserTimeQuery ())->setViewer ($viewer)->
          withUserPHIDs (array ($viewer->getPHID ()))->
          withObjectPHIDs (array ($phid))->
          withEnded (PhrequentUserTimeQuery::ENDED_NO)->executeOne ();
        if (!$current_timer)
          {
            return $this->newDialog ()->setTitle (pht ('Not Tracking Time'))->
              appendParagraph (pht
                               ('You are not currently tracking time on this object.'))->
              addCancelButton ($done_uri);
          }
        break;

      case 'delete':

        $request_data =  $request->getRequestData();
        $timelog_id =  $request_data['__timelog_id__'];
        $query = new PhrequentUserTimeQuery();
        $query->withIDs(array($timelog_id));
        $query->setViewer($viewer);
        $usertime = $query->executeOne();

        if ($usertime !== null) {
            if ($usertime->getObjectPHID() !== null &&
                   $usertime->getUserPHID() === $viewer->getPHID()) {

                $is_confirmed = array_key_exists('__confirm__', $request_data) && ($request_data['__confirm__'] == 'true');
                if ($is_confirmed) {
                    // actual delete
                    $usertime->delete();
                    $done_uri = $request_data['__back__'];
                    return id(new AphrontRedirectResponse ())
                      ->setURI($done_uri);
                } else {
                    $done_uri = $request_data['__back__'];
                    return $this->newDialog()->setTitle(pht('Timelog deletion'))
                      ->appendParagraph(
                        pht('Are you sure to delete this timelog?'))
                      ->addSubmitButton(pht('Yes, delete'))
                      ->addCancelButton($done_uri)
                      ->addHiddenInput('__timelog_id__', $timelog_id)
                      ->addHiddenInput('__confirm__', 'true')
                      ->addHiddenInput('__back__', $done_uri);
                }
            } else {
                  return $this->newDialog()
                    ->setTitle(pht('You are not the owner'))
                    ->appendParagraph(
                      pht('You cannot delete timelog created by another user.'))
                    ->addCancelButton($done_uri);
            }
          } else {
            return $this->newDialog()->setTitle(pht('Worklog not found'))
                    ->appendParagraph(
                    pht('I was unable to found the worklog you try to delete.'))
                    ->addCancelButton($done_uri);
          }
        break;


      default:
        return new Aphront404Response ();
      }

    $errors = array ();
    $v_note = null;
    $e_date = null;

    $e_worklog = null;
    $worklog = null;

    $timestamp = AphrontFormDateControlValue::newFromEpoch ($viewer, time ());

    if ($request->isDialogFormPost ())
      {
        $v_note = $request->getStr ('note');
        $worklog = $request->getStr ('worklog');
        $timestamp = AphrontFormDateControlValue::newFromRequest ($request, 'epoch');

        if (!$timestamp->isValid ())
          {
            $errors[] = pht ('Please choose a valid date.');
            $e_date = pht ('Invalid');
          }
        else
          {
            $max_time = PhabricatorTime::getNow ();
            if ($timestamp->getEpoch () > $max_time)
              {
                if ($this->isStoppingTracking ())
                  {
                    $errors[] =
                      pht
                      ('You can not stop tracking time at a future time. Enter the '.'current time, or a time in the past.');
                  }
                else
                  {
                    $errors[] =
                      pht
                      ('You can not start tracking time at a future time. Enter the '.'current time, or a time in the past.');
                  }
                $e_date = pht ('Invalid');
              }

            if ($this->isWorklog ())
              {
                if (strlen ($worklog) > 0)
                  {
                    $worklog_parser =
                      new WorklogParser ($timestamp->getEpoch (), $worklog);
                    $parse_error = $worklog_parser->getError ();
                    if (strlen ($parse_error) > 0)
                      {
                        $errors[] = $parse_error;
                        $e_worklog = pht ('Syntax error');
                      }
                  }
                else
                  {
                    $errors[] = pht ('Please type a worklog');
                    $e_worklog = pht ('Syntax error');
                  }
              }
            else if ($this->isStoppingTracking ())
              {
                $min_time = $current_timer->getDateStarted ();
                if ($min_time > $timestamp->getEpoch ())
                  {
                    $errors[] = pht ('Stop time must be after start time.');
                    $e_date = pht ('Invalid');
                  }
              }
          }

        if (!$errors)
          {
            $editor = new PhrequentTrackingEditor ();
            if ($this->isStartingTracking ())
              {
                $editor->startTracking ($viewer,
                                        $this->phid, $timestamp->getEpoch ());
              }
            else if ($this->isStoppingTracking ())
              {
                $editor->stopTracking ($viewer,
                                       $this->phid,
                                       $timestamp->getEpoch (), $v_note);
              }
            else if ($this->isWorklog ())
              {
                $editor->addWorklog ($viewer,
                                     $this->phid,
                                     $timestamp->getEpoch (), $worklog, $v_note);
              }

            return id (new AphrontRedirectResponse ())->setURI ($done_uri);
          }

      }

    $dialog =
      $this->newDialog ()->setTitle ($title_text)->
      setWidth (AphrontDialogView::WIDTH_FORM)->setErrors ($errors)->
      appendParagraph ($inner_text);

    $form = new PHUIFormLayoutView ();

    if ($this->isStoppingTracking ())
      {
        $start_time = $current_timer->getDateStarted ();
        $start_string = pht ('%s (%s ago)',
                             phabricator_datetime ($start_time, $viewer),
                             phutil_format_relative_time
                             (PhabricatorTime::getNow () - $start_time));

        $form->appendChild (id (new AphrontFormStaticControl ())->setLabel
                            (pht ('Started At'))->setValue ($start_string));
      }

    $form->appendChild (id (new AphrontFormDateControl ())->
                        setUser ($viewer)->setName ('epoch')->
                        setLabel ($label_text)->setError ($e_date)->
                        setValue ($timestamp));

    if ($this->isWorklog ())
      {
        if($worklog == ""){
          $worklog = '7h';
        }
        $form->appendChild (id (new AphrontFormTextControl ())->
                            setUser ($viewer)->setName ('worklog')->
                            setLabel ($worklog_action_text)->
                            setError ($e_worklog)->setValue ($worklog));

        $form->appendChild (id (new AphrontFormTextControl ())->setLabel
                            (pht ('Note'))->setName ('note')->setValue ($v_note));
      }

    if ($this->isStoppingTracking ())
      {
        $form->appendChild (id (new AphrontFormTextControl ())->setLabel
                            (pht ('Note'))->setName ('note')->setValue ($v_note));
      }

    $dialog->appendChild ($form);

    $dialog->addCancelButton ($done_uri);

    $dialog->addSubmitButton ($ok_button_text);

    return $dialog;
  }

  private function isStartingTracking ()
  {
    return $this->verb == 'start';
  }

  private function isStoppingTracking ()
  {
    return $this->verb == 'stop';
  }

  private function isWorklog ()
  {
    return $this->verb == 'worklog';
  }
}
