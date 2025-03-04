<?php
/**
 * Digital Download plugin for Craft CMS
 *
 * Provide secure digital download links to your files.
 *
 * @author    Double Secret Agency
 * @link      https://www.doublesecretagency.com/
 * @copyright Copyright (c) 2016 Double Secret Agency
 */

namespace doublesecretagency\digitaldownload\services;

use Craft;
use craft\base\Component;
use craft\base\FsInterface;
use craft\elements\Asset;
use craft\elements\User;
use craft\fs\Local;
use craft\helpers\App;
use craft\helpers\Json;
use craft\models\Volume;
use DateTime;
use DateTimeZone;
use doublesecretagency\digitaldownload\DigitalDownload;
use doublesecretagency\digitaldownload\models\Link;
use doublesecretagency\digitaldownload\records\Log as LogRecord;
use doublesecretagency\digitaldownload\records\Token as TokenRecord;
use Exception;
use yii\base\InvalidConfigException;
use yii\web\HttpException;

/**
 * Class Download
 * @since 2.0.0
 */
class Download extends Component
{

    /**
     * @var User|null Currently logged-in user.
     */
    private ?User $_user;

    /**
     * @var array User groups of currently logged-in user.
     */
    private array $_userGroups = [];

    /**
     * Initiate file download.
     *
     * @param string|null $token Token representing file to be downloaded.
     * @throws HttpException
     * @throws InvalidConfigException
     */
    public function startDownload(?string $token = null): void
    {
        // If no token provided, throw error message
        if (!$token) {
            throw new HttpException(403, 'No download token provided.');
        }

        // Get link data
        $link = DigitalDownload::$plugin->digitalDownload->getLinkData($token);

        // If no link data exists, throw error message
        if (!$link) {
            throw new HttpException(403, 'No data is associated with this token.');
        }

        // Get currently logged-in user
        $this->_user = Craft::$app->getUser()->getIdentity();

        // If user is logged in
        if ($this->_user) {

            // Populate array of user groups
            foreach ($this->_user->getGroups() as $group) {
                $this->_userGroups[] = $group->handle;
            }

        }

        // Check if download is authorized
        $authorized = $this->authorized($link);

        // If authorized, attempt file download
        if ($authorized) {
            $this->_outputFile($link);
        }

        // If something went wrong with the download
        if (!$link->error) {
            $link->error = 'Unknown error when downloading file.';
        }

        // Track failed download
        $this->trackDownload($link);

        // Something went wrong, throw error message
        throw new HttpException(403, $link->error);
    }

    /**
     * Track details of file download.
     *
     * @param Link $link Data regarding file download link.
     * @throws Exception
     */
    public function trackDownload(Link $link): void
    {
        // Get token record
        $tokenRecord = TokenRecord::findOne([
            'token' => $link->token
        ]);

        // If no token record, bail
        if (!$tokenRecord) {
            return;
        }

        // If no errors, increment token record
        if (!$link->error) {
            $tokenRecord->totalDownloads++;
            $tokenRecord->lastDownloaded = new DateTime();
            $tokenRecord->save();
        }

        // If not keeping a download log, bail
        if (!DigitalDownload::$plugin->getSettings()->keepDownloadLog) {
            return;
        }

        // Log download
        $log = new LogRecord();
        $log->tokenId   = $tokenRecord->id;
        $log->assetId   = $tokenRecord->assetId;
        $log->userId    = ($this->_user ? $this->_user->id : null);
        $log->ipAddress = $_SERVER['REMOTE_ADDR'];
        $log->success   = !$link->error;
        $log->error     = $link->error;
        $log->save();
    }

    // =========================================================================

    /**
     * Prepare to download file.
     *
     * @param Link $link Data regarding file download link.
     * @throws HttpException
     * @throws InvalidConfigException
     */
    private function _outputFile(Link $link): void
    {
        // Get asset of link
        $asset = $link->asset();

        // If no asset, bail
        if (!$asset) {
            $link->error = 'Link is missing an associated asset.';
            return;
        }

        /** @var Volume $volume */
        $volume = $asset->getVolume();

        /** @var FsInterface $filesystem */
        $filesystem = $volume->getFs();

        // Determine volume type
        if (get_class($filesystem) === Local::class) {

            // Get volume and folder paths
            $fsSettings = $filesystem->getSettings();
            $fsPath = App::parseEnv($fsSettings['path']).'/';
            $folderPath = $asset->getFolder()->path.'/';

            // Set local path (prevent double slashes)
            $filepath = preg_replace('#/+#', '/', $fsPath.$folderPath);

            // Set path for local file
            $assetFilePath = $filepath.$asset->filename;

        } else {

            // If no public URL, bail
            if (!$asset->url) {
                $link->error = 'Cloud assets require a public URL.';
                return;
            }

            // Set path for remote file (encode spaces)
            $assetFilePath = preg_replace('/ /', '%20', $asset->url);

        }

        // Track successful download
        $this->trackDownload($link);

        // Set any optional download headers
        $optionalHeaders = Json::decode($link->headers);

        // Start file download
        $this->_downloadFile($asset, $assetFilePath, $optionalHeaders);
    }

    /**
     * Process the file download.
     *
     * @param Asset $asset The file to be downloaded.
     * @param string $filePath Where the file is located.
     * @param array $optionalHeaders Optional additional headers.
     * @throws HttpException
     */
    private function _downloadFile(Asset $asset, string $filePath, array $optionalHeaders = []): void
    {
        // Unlimited PHP memory
        App::maxPowerCaptain();

        // Prevent timeouts
        set_time_limit(0);

        // Default headers
        $defaultHeaders = [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$asset->filename.'"',
            'Content-Length' => $asset->size,
        ];

        // Add optional headers
        $headers = array_merge($defaultHeaders, $optionalHeaders);

        // Start with a clean slate
        ob_start();

        // Open the file
        $file = @fopen($filePath, 'rb');

        // If file doesn't exist, throw an error
        if (!$file) {
            throw new HttpException(500, 'The file you are looking for does not exist.');
        }

        // Set file headers
        foreach ($headers as $name => $value) {
            header("{$name}: {$value}");
        }

        // Size of chunks
        $chunkSize = (1024 * 8);

        // Read the file out one chunk at a time
        while (!feof($file)) {
            echo fread($file, $chunkSize);

            // Flush to prevent overflow
            ob_flush();
            flush();

        }

        // Finish up
        ob_end_flush();

        // Close file
        fclose($file);
        exit;
    }

    // =========================================================================

    /**
     * Check whether file download is authorized.
     *
     * @param Link $link Data regarding file download link.
     * @return bool
     * @throws Exception
     */
    public function authorized(Link $link): bool
    {
        // If link is not enabled, set error and bail
        if (!$this->_isEnabled($link)) {
            $link->error = 'Download link is disabled.';
            return false;
        }

        // If link has expired, set error and bail
        if (!$this->_isUnexpired($link)) {
            $link->error = 'Download link has expired.';
            return false;
        }

        // If max downloads have been reached, set error and bail
        if (!$this->_isUnderMaxDownloads($link)) {
            $link->error = 'Maximum downloads have been reached.';
            return false;
        }

        // If user is not authorized
        if (!$this->_isAuthorizedUser($link)) {

            // If they are not logged in
            if (!$this->_user) {
                // Redirect to the login page
                Craft::$app->getUser()->loginRequired();
                Craft::$app->end();
            }

            // Set error and bail
            $link->error = 'User is not authorized to download file.';
            return false;
        }

        // Passed all checks
        return true;
    }

    /**
     * Check whether link is enabled.
     *
     * @param Link $link Data regarding file download link.
     * @return bool
     */
    private function _isEnabled(Link $link): bool
    {
        return $link->enabled;
    }

    /**
     * Check whether link has not yet expired.
     *
     * @param Link $link Data regarding file download link.
     * @return bool
     * @throws Exception
     */
    private function _isUnexpired(Link $link): bool
    {
        try {
            // Determine expiration timestamp
            $expires = $link->expires->setTimezone(new DateTimeZone('UTC'));
            $end = (int) $expires->format('U');
        } catch (Exception $e) {
            // Assume link doesn't expire
            return true;
        }

        // Determine current timestamp
        $current = new DateTime();
        $now = (int) $current->format('U');

        // Whether link has expired
        return ($now < $end);
    }

    /**
     * Check whether link has reached the maximum number of permitted downloads.
     *
     * @param Link $link Data regarding file download link.
     * @return bool
     */
    private function _isUnderMaxDownloads(Link $link): bool
    {
        // If no download maximum is set, return true
        if (!$link->maxDownloads) {
            return true;
        }

        // Whether the total downloads has not yet reached the maximum
        return ($link->totalDownloads < $link->maxDownloads);
    }

    // =========================================================================

    /**
     * Check whether user is authorized to download file.
     *
     * @param Link $link Data regarding file download link.
     * @return bool
     */
    private function _isAuthorizedUser(Link $link): bool
    {
        // Decode user requirements
        $requirement = Json::decode($link->requireUser);

        // All access granted (including anonymous)
        if (null === $requirement) {
            return true;
        }

        // User must be logged in
        if ('*' === $requirement) {
            return isset($this->_user->id);
        }

        // User ID must match specified ID
        if (is_numeric($requirement)) {
            return $this->_isCurrentUser((int) $requirement);
        }

        // User must be a member of allowed group
        if (is_string($requirement)) {
            return $this->_isCurrentUserInGroup($requirement);
        }

        // Multiple users or groups
        if (is_array($requirement)) {

            // Loop through each requirement
            foreach ($requirement as $userOrGroup) {

                // User ID must match specified ID
                if (is_numeric($userOrGroup) && $this->_isCurrentUser((int) $userOrGroup)) {
                    return true;
                }

                // User must be a member of allowed group
                if (is_string($userOrGroup) && $this->_isCurrentUserInGroup($userOrGroup)) {
                    return true;
                }

            }

        }

        // Failed, user is not authorized
        return false;
    }

    /**
     * Check whether the current user is allowed to download the file.
     *
     * @param int $userId An ID of a permitted user.
     * @return bool
     */
    private function _isCurrentUser(int $userId): bool
    {
        // If there is no current user, return false
        if (!$this->_user) {
            return false;
        }

        // Whether the specified ID matches the current user ID
        return ($this->_user->id == $userId);
    }

    /**
     * Check whether the current user is in a group allowed to download the file.
     *
     * @param string $group A permitted user group.
     * @return bool
     */
    private function _isCurrentUserInGroup(string $group): bool
    {
        return in_array($group, $this->_userGroups, true);
    }

}
