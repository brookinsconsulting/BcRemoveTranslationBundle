<?php
/**
 * File containing the BcRemoveTranslationBundle class part of the BcRemoveTranslationBundle package.
 *
 * @copyright Copyright (C) Brookins Consulting. All rights reserved.
 * @license For full copyright and license information view LICENSE and COPYRIGHT.md file distributed with this source code.
 * @version //autogentag//
 * @package bcremovetranslationbundle
 */

namespace BrookinsConsulting\BcRemoveTranslationBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use eZ\Publish\API\Repository\ContentService;
use eZ\Publish\API\Repository\ContentTypeService;
use eZ\Publish\API\Repository\LocationService;
use eZ\Publish\API\Repository\Values\Content\Content;
use eZ\Publish\API\Repository\Values\Content\Location;
use eZ\Publish\API\Repository\Values\Content\ContentCreateStruct;
use eZ\Publish\API\Repository\Values\Content\LocationCreateStruct;
use eZ\Publish\API\Repository\Values\Content\VersionInfo;
use eZ\Publish\API\Repository\Values\ContentType\ContentType;

class RemoveTranslationCommand extends ContainerAwareCommand
{
    /**
     * @var int
     */
    protected $contentId;

    /**
     * @var int
     */
    protected $locationId;

    /**
     * @var int
     */
    protected $adminUserID;

    /**
     * @var string
     */
    protected $localeLanguage;

    /**
     * @var string
     */
    protected $removeLanguage;

    /**
     * @var bool
     */
    protected $disableRemoveConfirmation;

    /**
     * Configure command's options / arguments
     *
     * @return void
     */
    protected function configure()
    {
        $this->setName('bc:rt:remove-translation')
            ->setDescription('Remove a content location translation')
            ->addOption(
                'contentId', null, InputOption::VALUE_REQUIRED,
                'Which content location to modify. Required. Example --contentId=42',
                false
            )
            ->addOption(
                'locationId', null, InputOption::VALUE_REQUIRED,
                'Which content location to modify. Required. Example --locationId=42',
                false
            )
            ->addOption(
                'removeLanguage', null, InputOption::VALUE_REQUIRED,
                'Which translation language code to remove. Required. Example --removeLanguage=eng-US',
                false
            )
            ->addOption(
                'language', null, InputOption::VALUE_OPTIONAL,
                'Which content location language to modify. Optional. Defaults to eng-GB',
                'eng-GB'
            )
            ->addOption(
                'adminUserID', null, InputOption::VALUE_OPTIONAL,
                'Which admin user Id should be used to modify the content locations. Optional. Defaults to 14',
                14
            )
            ->addOption(
                'disableRemoveConfirmation', null, InputOption::VALUE_NONE,
                'Would you like to manually confirm the translation removal. Optional.'
            );
    }

    /**
     * Execute command
     *
     * @param Symfony\Component\Console\Input\InputInterface $input
     * @param Symfony\Component\Console\Output\OutputInterface $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Assign command line options to class properties
        $this->contentId = $input->getOption('contentId');
        $this->locationId = $input->getOption('locationId');
        $this->removeLanguage = $input->getOption('removeLanguage');
        $this->localeLanguage = $input->getOption('language');
        $this->disableRemoveConfirmation = $input->getOption('disableRemoveConfirmation');
        $this->adminUserID = $input->getOption('adminUserID');

        // Remove the content location translation
        $this->removeTranslation($input, $output);
    }

    /**
     * Remove Translation
     *
     * @param Symfony\Component\Console\Input\InputInterface $input
     * @param Symfony\Component\Console\Output\OutputInterface $output
     * @return void
     */
    protected function removeTranslation(InputInterface $input, OutputInterface $output)
    {
        /** Alter user and clearly notifiy them of the actions about to transpire **/
        $title = "Warning! Content Translation Removal ...";
        $output->writeln("<info>$title</info>");
        $output->writeln(str_repeat('=', strlen($title)));

        $repository = $this->getContainer()->get('ezpublish.api.repository');

        $repository->setCurrentUser($repository->getUserService()->loadUser($this->adminUserID));

        /* @var $contentService ContentService */
        $contentService = $repository->getContentService();

        /* @var $contentTypeService ContentTypeService */
        $contentTypeService = $repository->getContentTypeService();

        /* @var $locationService LocationService */
        $locationService = $repository->getLocationService();

        if($this->locationId) {
            $location = $locationService->loadLocation($this->locationId);
            $this->contentId = $location->contentInfo->id;
            /* @var $content Content */
            $content = $contentService->loadContent($this->contentId);
            $contentMainLocationId = $content->contentInfo->mainLocationId;

        } else {
            /* @var $content Content */
            $content = $contentService->loadContent($this->contentId);
            $contentMainLocationId = $content->contentInfo->mainLocationId;

            /* @var $location Location */
            $location = $locationService->loadLocation($contentMainLocationId);
        }

        /* @var $versionInfo VersionInfo */
        $versionInfo = $contentService->loadVersionInfoById($this->contentId);
        $languageCodes = implode($versionInfo->languageCodes, ', ');

        $contentParentLocationId = $location->parentLocationId;

        /* @var $contentType ContentType */
        $contentType = $contentTypeService->loadContentType($content->contentInfo->contentTypeId);
        $contentTypeName = $contentType->getName($this->localeLanguage);

        $output->writeln("Processing content location: <info>{$versionInfo->names[$versionInfo->languageCodes[0]]}</info>");
        $output->writeln("Content of type <info>$contentTypeName</info> is translated in <info>$languageCodes</info>");

        if (!in_array($this->removeLanguage, $versionInfo->languageCodes)) {
          $output->writeln("<error>Error</error>: No translation for <info>" . $this->removeLanguage . "</info> found.");
          return;
        }

        $newMainLanguage = false;

        foreach ($versionInfo->languageCodes as $languageCode) {
            if (!$newMainLanguage && $languageCode != $this->removeLanguage)
                $newMainLanguage = $languageCode;
        }

        if (!$newMainLanguage) {
            $output->writeln("<error>Error</error>: No translations left if <info>" . $this->removeLanguage . "</info> is removed.");
            return;
        }

        if(!$this->disableRemoveConfirmation) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion("Please confirm you wish to remove the <info>" . $this->removeLanguage . "</info> translation? [Yes, No] ", false);

            if (!$helper->ask($input, $output, $question)) {
              return;
            }
        }

        $translationRemovalResult = false;

        /** Remove translation **/
        try {
            /* @var $locationCreateStruct LocationCreateStruct */
            $locationCreateStruct = $locationService->newLocationCreateStruct($location->parentLocationId);

            /* @var $contentCreateStruct ContentCreateStruct */
            $contentCreateStruct = $contentService->newContentCreateStruct($contentType, $newMainLanguage);

            foreach ($content->fields as $fieldIdentifier => $field) {
              foreach ($field as $languageCode => $fieldValue) {
                if ($languageCode != $this->removeLanguage) {
                  $contentCreateStruct->setField($fieldIdentifier, $fieldValue, $languageCode);
                }
              }
            }

            $draft = $contentService->createContent($contentCreateStruct, array($locationCreateStruct));
            $newContent = $contentService->publishVersion($draft->versionInfo);
            $newcontentMainLocationId = $newContent->contentInfo->mainLocationId;
            $newLocation = $locationService->loadLocation($newcontentMainLocationId);

            $locationService->swapLocation($location, $newLocation);

            $content = $contentService->loadContent($this->contentId);
            $contentService->deleteContent($content->contentInfo);

            /** Clear content location cache **/
            $locationIds = array($contentParentLocationId, $contentMainLocationId, $newcontentMainLocationId);
            $this->getContainer()->get('ezpublish.http_cache.purger')->purge($locationIds);

            $translationRemovalResult = true;
        }
        catch (Exception $e) {
            echo $e->getMessage();
        }

        if ($translationRemovalResult)
        {
            $output->writeln("The translation was <info>removed</info>");
        }

        $output->writeln("\nComand execution completed successfully!");
    }
}