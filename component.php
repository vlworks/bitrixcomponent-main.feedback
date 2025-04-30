<?php


use Vlworks\Helper\FeedbackFields;

if(!defined("B_PROLOG_INCLUDED")||B_PROLOG_INCLUDED!==true)die();

/**
 * Bitrix vars
 *
 * @var array $arParams
 * @var array $arResult
 * @var CBitrixComponent $this
 * @global CMain $APPLICATION
 * @global CUser $USER
 */

/**
 * FeedbackFields
 * Поля создаются из единого места и используются в компоненте, параметрах
 * см. /local/php_interface/include/functions.php
 */
$feedbackFields = FeedbackFields::getInstance();
$fieldsReq = $feedbackFields->getForComponentsReq();

$arResult["PARAMS_HASH"] = md5(serialize($arParams).$this->GetTemplateName());

$arParams["USE_CAPTCHA"] = (($arParams["USE_CAPTCHA"] != "N" && !$USER->IsAuthorized()) ? "Y" : "N");
$arParams["EVENT_NAME"] = trim($arParams["EVENT_NAME"]);
if($arParams["EVENT_NAME"] == '')
	$arParams["EVENT_NAME"] = "FEEDBACK_FORM";

/**
 * Ньюанс :)
 * Есть форма обратной связи - заказать звонок, в ней EMAIL_TO не указывается спициально, он меняется в зависимости от выбранного филиала.
 * В подобных шаблонах в форме присутствует скрытое поле name="EMAIL_TO", куда помещается необходимый Email и передается в шаблон сообщения
 */
$arParams["EMAIL_TO"] = trim($arParams["EMAIL_TO"]);
if (isset($_POST["email_to"]))
    $arParams["EMAIL_TO"] = $_POST["email_to"];

if ($arParams['EMAIL_TO'] == '')
    $arParams["EMAIL_TO"] = COption::GetOptionString("main", "email_from");


$arParams["OK_TEXT"] = trim($arParams["OK_TEXT"]);
if($arParams["OK_TEXT"] == '')
	$arParams["OK_TEXT"] = GetMessage("MF_OK_MESSAGE");

if($_SERVER["REQUEST_METHOD"] == "POST" && $_POST["submit"] <> '' && (!isset($_POST["PARAMS_HASH"]) || $arResult["PARAMS_HASH"] === $_POST["PARAMS_HASH"]))
{
	$arResult["ERROR_MESSAGE"] = array();
	if(check_bitrix_sessid())
	{
        if($arParams["USE_CAPTCHA"] == "Y")
        {
            if (empty($_POST['token'])) {
                $arResult["ERROR_MESSAGE"]["CAPTCHA"] = 'Не передан токен Yandex Smart Captcha';
            } else {
                $secret = YANDEX_CAPTCHA_SECRET;
                $token = $_POST['token'];

                $response = file_get_contents('https://smartcaptcha.yandexcloud.net/validate?secret=' . $secret . '&token=' . $token);
                $result = json_decode($response);

                if ($result->status !== 'ok' && $result->host !== $_SERVER['SERVER_NAME']) {
                    $arResult["ERROR_MESSAGE"]["CAPTCHA"] = 'Ошибка прохождения Yandex Smart Captcha';
                }
            }
        }

        if(empty($arParams["REQUIRED_FIELDS"]) || !in_array("NONE", $arParams["REQUIRED_FIELDS"]))
		{
            foreach ($fieldsReq as $arField)
            {
                if((empty($arParams["REQUIRED_FIELDS"]) || in_array($arField["NAME"], $arParams["REQUIRED_FIELDS"])) && mb_strlen($_POST[$arField["POST"]]) <= 3)
                    $arResult["ERROR_MESSAGE"][$arField["NAME"]] = "Y";
            }
		}
		if(mb_strlen($_POST["user_email"]) > 3 && !check_email($_POST["user_email"]))
			$arResult["ERROR_MESSAGE"]["EMAIL"] = "Y";

		if(empty($arResult["ERROR_MESSAGE"]))
		{
			$arFields = Array(
				"EMAIL_TO" => $arParams["EMAIL_TO"],
			);
            foreach ($fieldsReq as $arField)
            {
                $arFields["AUTHOR_".$arField["NAME"]] = $_POST[$arField["POST"]];
            }


			if(!empty($arParams["EVENT_MESSAGE_ID"]))
			{
				foreach($arParams["EVENT_MESSAGE_ID"] as $v)
					if(intval($v) > 0)
						CEvent::Send($arParams["EVENT_NAME"], SITE_ID, $arFields, "N", intval($v));
			}
			else
				CEvent::Send($arParams["EVENT_NAME"], SITE_ID, $arFields);

			$_SESSION["MF_NAME"] = htmlspecialcharsbx($_POST["user_name"]);
			$_SESSION["MF_EMAIL"] = htmlspecialcharsbx($_POST["user_email"]);
			$event = new \Bitrix\Main\Event('main', 'onFeedbackFormSubmit', $arFields);
			$event->send();

			LocalRedirect($APPLICATION->GetCurPageParam("success=".$arResult["PARAMS_HASH"], Array("success")));
		}

        foreach ($fieldsReq as $arField)
        {
            $arResult["AUTHOR_".$arField["NAME"]] = htmlspecialcharsbx($_POST[$arField["POST"]]);
        }
	}
	else
		$arResult["ERROR_MESSAGE"][] = GetMessage("MF_SESS_EXP");
}
elseif($_REQUEST["success"] == $arResult["PARAMS_HASH"])
{
	$arResult["OK_MESSAGE"] = $arParams["OK_TEXT"];
}

if(empty($arResult["ERROR_MESSAGE"]))
{
	if($USER->IsAuthorized())
	{
		$arResult["AUTHOR_NAME"] = $USER->GetFormattedName(false);
		$arResult["AUTHOR_EMAIL"] = htmlspecialcharsbx($USER->GetEmail());
	}
	else
	{
		if($_SESSION["MF_NAME"] <> '')
			$arResult["AUTHOR_NAME"] = htmlspecialcharsbx($_SESSION["MF_NAME"]);
		if($_SESSION["MF_EMAIL"] <> '')
			$arResult["AUTHOR_EMAIL"] = htmlspecialcharsbx($_SESSION["MF_EMAIL"]);
	}
}

$this->IncludeComponentTemplate();

if($arParams["USE_CAPTCHA"] == "Y")
{
    ?>
    <script>
        function yCaptcha() {
            const hashInput = document.querySelector('input[value="<?=$arResult['PARAMS_HASH']?>"]')
            const form = hashInput.parentElement;
            const btn = form?.querySelector('button');

            btn?.addEventListener('click', (e) => {
                    if (!window.smartCaptcha) {
                        return;
                    }

                    if (window.smartCaptcha.getResponse()) {
                        if (!form.querySelector('input[name="token"]')) {
                            const tokenInput = document.createElement('input');
                            tokenInput.setAttribute('type', 'hidden');
                            tokenInput.setAttribute('name', 'token');
                            tokenInput.value = window.smartCaptcha.getResponse();

                            form.appendChild(tokenInput);
                        }
                    } else {
                        e.preventDefault();
                        Object.assign(window.smartCaptcha, {btn: btn})
                        window.smartCaptcha.execute(widgetId);
                    }
                }
            )
        }
        yCaptcha();
    </script>
    <?php
}