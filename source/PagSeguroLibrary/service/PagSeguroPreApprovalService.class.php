<?php
/**
 * 2007-2014 [PagSeguro Internet Ltda.]
 *
 * NOTICE OF LICENSE
 *
 *Licensed under the Apache License, Version 2.0 (the "License");
 *you may not use this file except in compliance with the License.
 *You may obtain a copy of the License at
 *
 *http://www.apache.org/licenses/LICENSE-2.0
 *
 *Unless required by applicable law or agreed to in writing, software
 *distributed under the License is distributed on an "AS IS" BASIS,
 *WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *See the License for the specific language governing permissions and
 *limitations under the License.
 *
 *  @author    André da Silva Medeiros <andre@swdesign.net.br>
 *  @copyright 2007-2014 PagSeguro Internet Ltda.
 *  @license   http://www.apache.org/licenses/LICENSE-2.0
 */

/***
 * Encapsulates web service calls regarding PagSeguro payment requests
 */
class PagSeguroPreApprovalService
{

    /***
     *
     */
    const SERVICE_NAME = 'preApproval';
    private static $logService;

    /***
     * @param PagSeguroConnectionData $connectionData
     * @return string
     */
    private static function buildCheckoutRequestUrl(PagSeguroConnectionData $connectionData)
    {
        return $connectionData->getServiceUrl();
    }

    /***
     * @param PagSeguroConnectionData $connectionData
     * @param $code
     * @return string
     */
    private static function buildCheckoutUrl(PagSeguroConnectionData $connectionData, $code)
    {
        return $connectionData->getPaymentUrl() . $connectionData->getResource('checkoutUrl') . "?code=$code";
    }

    /***
     * @param PagSeguroConnectionData $connectionData
     * @param $notificationCode
     * @return string
     */
    private static function buildFindByNotificationUrl(PagSeguroConnectionData $connectionData, $notificationCode)
    {
        $url = $connectionData->getWebserviceUrl() . $connectionData->getResource('findUrl') . 'notification';
        return "{$url}/{$notificationCode}/?" . $connectionData->getCredentialsUrlQuery();
    }

    /***
     * @param PagSeguroConnectionData $connectionData
     * @param $code
     * @return string
     */
    private static function buildFindByCodeUrl(PagSeguroConnectionData $connectionData, $code)
    {
        $url = $connectionData->getWebserviceUrl() . $connectionData->getResource('findUrl');
        return "{$url}{$code}/?" . $connectionData->getCredentialsUrlQuery();
    }

    /***
     * @param PagSeguroConnectionData $connectionData
     * @param $code
     * @return string
     */
    private static function buildCancelUrl(PagSeguroConnectionData $connectionData, $code)
    {
        $credentialsArray = $connectionData->getCredentials()->getAttributesMap();
        return $connectionData->getWebserviceUrl() .
        $connectionData->getResource('cancelUrl') .
        "$code?email={$credentialsArray['email']}&token={$credentialsArray['token']}";
    }

    /***
     * @param PagSeguroCredentials $credentials
     * @param PagSeguroPaymentRequest $paymentRequest
     * @return array('code', 'cancelUrl', 'checkoutUrl')
     * @throws Exception|PagSeguroServiceException
     * @throws Exception
     */
    public static function createPreApprovalRequest(
        PagSeguroCredentials $credentials,
        PagSeguroPaymentRequest $paymentRequest
    ) {

        LogPagSeguro::info("PagSeguroPreApprovalService.createPreApprovalRequest(" .
            $paymentRequest->toString() . ") - begin");

        $connectionData = new PagSeguroConnectionData($credentials, self::SERVICE_NAME);
        $data = array_merge($connectionData->getCredentials()->getAttributesMap(),
            PagSeguroPreApprovalParser::getData($paymentRequest));

        try {

            $connection = new PagSeguroHttpConnection();
            $connection->post(
                self::buildCheckoutRequestUrl($connectionData),
                $data,
                $connectionData->getServiceTimeout(),
                $connectionData->getCharset()
            );

            $httpStatus = new PagSeguroHttpStatus($connection->getStatus());

            switch ($httpStatus->getType()) {

                case 'OK':
                    $PaymentParserData = PagSeguroPreApprovalParser::readSuccessXml($connection->getResponse());

                    $paymentReturn = array ( 'code' => $PaymentParserData->getCode(),
                        'cancelUrl' => self::buildCancelUrl($connectionData,
                            $PaymentParserData->getCode()),
                        'checkoutUrl' => self::buildCheckoutUrl($connectionData,
                            $PaymentParserData->getCode()) );
                    LogPagSeguro::info(
                        "PagSeguroPreApprovalService.createPreApprovalRequest(" .
                        $paymentRequest->toString() . ") - end {1}" .
                        $PaymentParserData->getCode()
                    );
                    break;

                case 'BAD_REQUEST':
                    $errors = PagSeguroPreApprovalParser::readErrors($connection->getResponse());
                    $e = new PagSeguroServiceException($httpStatus, $errors);
                    LogPagSeguro::error(
                        "PagSeguroPreApprovalService.createPreApprovalRequest(" .
                        $paymentRequest->toString() . ") - error " .
                        $e->getOneLineMessage()
                    );
                    throw $e;
                    break;

                default:
                    $e = new PagSeguroServiceException($httpStatus);
                    LogPagSeguro::error(
                        "PagSeguroPreApprovalService.createPreApprovalRequest(" .
                        $paymentRequest->toString() . ") - error " .
                        $e->getOneLineMessage()
                    );
                    throw $e;
                    break;

            }
            return (isset($paymentReturn) ? $paymentReturn : false);

        } catch (PagSeguroServiceException $e) {
            throw $e;
        }
        catch (Exception $e) {
            LogPagSeguro::error("Exception: " . $e->getMessage());
            throw $e;
        }

    }

    /***
     * Returns a transaction from a code
     *
     * @param PagSeguroCredentials $credentials
     * @param String $notificationCode
     * @throws PagSeguroServiceException
     * @throws Exception
     * @return PagSeguroTransaction
     * @see PagSeguroTransaction
     */
    public static function findByCode(PagSeguroCredentials $credentials, $code)
    {

        LogPagSeguro::info("PagSeguroNotificationService.FindByCode($code) - begin");
        $connectionData = new PagSeguroConnectionData($credentials, self::SERVICE_NAME);

        try {

            $connection = new PagSeguroHttpConnection();
            $connection->get(
                self::buildFindByCodeUrl($connectionData, $code),
                $connectionData->getServiceTimeout(),
                $connectionData->getCharset()
            );
            self::$logService = "FindByCode";
            return self::searchReturn($connection, $code);

        } catch (PagSeguroServiceException $err) {
            LogPagSeguro::error("PagSeguroServiceException: " . $err->getMessage());
            throw $err;
        } catch (Exception $err) {
            LogPagSeguro::error("Exception: " . $err->getMessage());
            throw $err;
        }
    }

    /***
     * Returns a transaction from a notification code
     *
     * @param PagSeguroCredentials $credentials
     * @param String $notificationCode
     * @throws PagSeguroServiceException
     * @throws Exception
     * @return PagSeguroTransaction
     * @see PagSeguroTransaction
     */
    public static function findByNotification(PagSeguroCredentials $credentials, $notificationCode)
    {

        LogPagSeguro::info("PagSeguroNotificationService.FindByNotification($notificationCode) - begin");
        $connectionData = new PagSeguroConnectionData($credentials, self::SERVICE_NAME);

        try {

            $connection = new PagSeguroHttpConnection();
            $connection->get(
                self::buildFindByNotificationUrl($connectionData, $notificationCode),
                $connectionData->getServiceTimeout(),
                $connectionData->getCharset()
            );
            self::$logService = "FindByNotification";
            return self::searchReturn($connection, $notificationCode);

        } catch (PagSeguroServiceException $err) {
            LogPagSeguro::error("PagSeguroServiceException: " . $err->getMessage());
            throw $err;
        } catch (Exception $err) {
            LogPagSeguro::error("Exception: " . $err->getMessage());
            throw $err;
        }
    }

    /**
     * @param PagSeguroHttpConnection $connection
     * @param string $code
     * @return bool|mixed|string
     * @throws PagSeguroServiceException
     */
    private function searchReturn($connection, $code)
    {
        $httpStatus = new PagSeguroHttpStatus($connection->getStatus());

        switch ($httpStatus->getType()) {

            case 'OK':
                $return = PagSeguroPreApprovalParser::readPreApproval($connection->getResponse());
                LogPagSeguro::info(
                    sprintf("PagSeguroNotificationService.%s($code) - end ", self::$logService) .
                    $return->toString() . ")"
                );

                break;

            case 'BAD_REQUEST':

                $errors = PagSeguroServiceParser::readErrors($connection->getResponse());

                $err = new PagSeguroServiceException($httpStatus, $errors);
                LogPagSeguro::info(
                    sprintf("PagSeguroNotificationService.%s($code) - error ", self::$logService) .
                    $err->getOneLineMessage()
                );
                throw $err;
                break;

            default:
                $err = new PagSeguroServiceException($httpStatus);
                LogPagSeguro::info(
                    sprintf("PagSeguroNotificationService.%s($code) - error ", self::$logService) .
                    $err->getOneLineMessage()
                );
                throw $err;
                break;
        }
        return isset($return) ? $return : null;
    }


    /***
     * Request a pre approval cancelling
     *
     * @param PagSeguroCredentials $credentials
     * @param String $notificationCode
     * @throws PagSeguroServiceException
     * @throws Exception
     * @return true if ok, error message otherwise
     */
    public static function cancelPreApproval(PagSeguroCredentials $credentials, $notificationCode)
    {

        LogPagSeguro::info("PagSeguroNotificationService.cancelPreApproval(
            notificationCode=$notificationCode) - begin");
        $connectionData = new PagSeguroConnectionData($credentials, self::SERVICE_NAME);

        try {

            $connection = new PagSeguroHttpConnection();
            $connection->get(
                self::buildCancelUrl($connectionData, $notificationCode),
                $connectionData->getServiceTimeout(),
                $connectionData->getCharset()
            );

            $httpStatus = new PagSeguroHttpStatus($connection->getStatus());

            switch ($httpStatus->getType()) {

                case 'OK':
                    $parserData = PagSeguroPreApprovalParser::readCancelXml($connection->getResponse());

                    LogPagSeguro::info(
                        "PagSeguroPreApprovalService.cancelPreApproval(".$parserData->getStatus().")
                        - end \{$notificationCode\}"
                    );
                    break;

                case 'BAD_REQUEST':
                    $errors = PagSeguroPreApprovalParser::readErrors($connection->getResponse());
                    $e = new PagSeguroServiceException($httpStatus, $errors);
                    LogPagSeguro::error(
                        "PagSeguroPreApprovalService.cancelPreApproval(notificationCode=$notificationCode) - error " .
                        $e->getOneLineMessage()
                    );
                    throw $e;
                    break;

                default:
                    $e = new PagSeguroServiceException($httpStatus);
                    LogPagSeguro::error(
                        "PagSeguroPreApprovalService.cancelPreApproval(notificationCode=$notificationCode) - error " .
                        $e->getOneLineMessage()
                    );
                    throw $e;
                    break;

            }

            return (isset($parserData) ? $parserData : false);

        } catch (PagSeguroServiceException $err) {
            LogPagSeguro::error("PagSeguroServiceException: " . $err->getMessage());
            throw $err;
        } catch (Exception $err) {
            LogPagSeguro::error("Exception: " . $err->getMessage());
            throw $err;
        }
    }
}
