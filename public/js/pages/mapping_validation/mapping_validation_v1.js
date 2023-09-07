function validateConnections(dc,sc)
{
        //call save connection then store mapping
        if (sc == '') {
            source_platform_name = $('#source_platform_id').data('name');
            toastr.error('Please select your ' + source_platform_name + ' account');
            return;
        }
        if (dc == '') {
            destination_platform_name = $('#destination_platform_id').data('name');
            toastr.error('Please select your ' + destination_platform_name + ' account');
            return; 
        }

        if (sc == '' || dc == '') {
            toastr.error('Please select your source and destination account');
            return;
        }
}

function validateIdentity(validErrStatus,identData)
{
    const AvailIdentSide = JSON.parse($("#AvailIdentitySides").val());
    let mapping_type="regular";
    if(AvailIdentSide.length==1)
    {
        mapping_type = "default";
    } 
    //if source side available
    let unqIdentSource=null; let unqIdentDest=null; let platform_object_id="";
    if(AvailIdentSide.includes("source"))
    {
        platform_object_id = $("#unqIdentSource").attr("alt");
        unqIdentSource = $("#unqIdentSource").val();
        if (unqIdentSource == "") {
            $(".unqIdentSource").css({ "border-color": "red", "border-width": "1px", "border-style": "solid" });
            validErrStatus=1;
        }
        else {
            $(".unqIdentSource").css({ "border-color": "#D8D6DE", "border-width": "1px", "border-style": "solid" });
        }
    }
    //if destination side available
    if(AvailIdentSide.includes("destination"))
    {   
        platform_object_id = $("#unqIdentDest").attr("alt");
        unqIdentDest = $("#unqIdentDest").val();
        if (unqIdentDest == "") {
            $(".unqIdentDest").css({ "border-color": "red", "border-width": "1px", "border-style": "solid" });
            validErrStatus=1;
        }
        else {
            $(".unqIdentDest").css({ "border-color": "#D8D6DE", "border-width": "1px", "border-style": "solid" });
        }
    }
    identData = `{"data_map_type":"field","platform_object_id":${platform_object_id},"mapping_type":"${mapping_type}","source_row_id":${unqIdentSource},"destination_row_id":${unqIdentDest},"status":1}`;

    return identData;
}

