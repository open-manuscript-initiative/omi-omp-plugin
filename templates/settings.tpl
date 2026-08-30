<script>
$(function() {
    $('#studioIntegrationSettings').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
});
</script>
<form class="pkp_form" id="studioIntegrationSettings" method="post" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
    {csrf}
    {fbvFormArea id="studioIntegrationSettingsArea"}
        {fbvFormSection title="plugins.generic.studioIntegration.settings.connection"}
            {fbvElement type="text" id="studioUrl" value=$studioUrl label="plugins.generic.studioIntegration.settings.studioUrl" size=$fbvStyles.size.LARGE}
            {fbvElement type="text" id="installationId" value=$installationId label="plugins.generic.studioIntegration.settings.installationId" size=$fbvStyles.size.LARGE}
            {fbvElement type="text" id="sharedSecret" value=$sharedSecret label="plugins.generic.studioIntegration.settings.sharedSecret" size=$fbvStyles.size.LARGE}
            {fbvElement type="text" id="tokenTtl" value=$tokenTtl label="plugins.generic.studioIntegration.settings.tokenTtl" size=$fbvStyles.size.SMALL}
        {/fbvFormSection}
        {fbvFormButtons submitText="common.save" hideCancel=true}
    {/fbvFormArea}
</form>
