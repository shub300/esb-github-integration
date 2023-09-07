function openMapping(type = 1,isConnectionPage=0) {
    let userIntegId = $("#input_IntegPlateformId").val();
    var fixedUrl = $("#AjaxCallUrl").val();
    var contentServerPath = $("#contentServerPath").val();
    let mappingActionType;
    let newData =  {
        'userIntegId' : userIntegId,
        'isConnectionPage' : isConnectionPage,
    }
    var err = false;
    if(type == 1)
    {
        mappingActionType="NormalMapping";
    }
    else if (type == 2) {
        let dc = $('#destination_platform').val();
        let sc = $('#source_platform').val();

        if(dc && sc){   /** sometime variable contains string 'add-new' in case when user selected Add New platform Account option */
            if (isNaN(dc) === true || isNaN(sc) === true) {
                err = true;
            }
            else{
                //check platforms during flow creation
                validateConnections(dc,sc);
                mappingActionType="RefreshMapping";
                newData.dc=dc;
                newData.sc=sc;
            }
        }
        else{
            err = true;
        }
    }
    else if(type == 3)
    {
        mappingActionType="RefreshMapping";
    }

    if(err){
        toastr.error("Please select account from both platforms.");
        return false;
    }

    newData.mappingActionType=mappingActionType;
    showOverlay()
    //call ajax to get mapping in normal integration flow case
    $.ajax({
        type: 'get',
        url: fixedUrl + '/getMappingFields',
        data: newData,
        success: function (data) {
            hideOverlay()
            if(data.status_code==0)
            {
                toastr.error(data.status_text);
            }
            else
            {
                //get mapping content & append to MappingDataContainer
                let mappingContents = JSON.parse(data.mappingContents);
                document.getElementById("MappingDataContainer").innerHTML = mappingContents;
                //make select2 & datepicker
                makeSelect2();
                let timeZone = 'UTC';
                $(".flatpickr-date-time").flatpickr({ 
                    enableTime: true, 
                    // defaultDate: new Date()
                 });
    

                var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
                var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl)
                })
                $(".mappingSaveBtn").css('display','block');

                let storeMappingFiles=""; let dynDropzone="";
                let fileMapValidationArr = JSON.parse($("#fileMapValidationArr").val());
                $.each(fileMapValidationArr,function(key,pobjName){
                    //make dropzone
                    window['myDropzone_'+pobjName] = new Dropzone("#"+pobjName, { 
                        autoProcessQueue: false,
                    acceptedFiles: ['.csv','.txt'].join(','),
                    // acceptedFiles: ['.png','.csv','.txt'].join(','),
                        addRemoveLinks: true,
                        removedfile: function(file) {
                            var name = file.name; 
                            var userIntegId = $("#input_IntegPlateformId").val();
                            let platform = $("#"+pobjName).attr("data-platform");
                            let editid = $("#"+pobjName).attr("data-editid");

                            if(file.status=="queued" || file.status=="added")
                            {
                                file.previewElement.remove();
                            }
                            else
                            {
                                confirmationDelFile("Confirm!","Are you sure you want to delete this file?",name,platform,editid,userIntegId,file,type,isConnectionPage)
                            } 
                        } 

                    });

                    //load uploaded images
                  let StoredMappingFiles = JSON.parse($(".storeMappingFiles_"+pobjName).val());
                   $.each(StoredMappingFiles,function(key,val){

                        previewThumbailFromUrl({
                            selector: pobjName,
                            fileName: val.substring(val.lastIndexOf("/") + 1, val.length),
                            imageURL: fixedUrl+'/'+val,
                            t : this
                        });

                   })
                    //end
                    
                })
                
                //manage full inventory sync time picker
                let countMultiField = JSON.parse($("#multiFieldMapValidationArr").val()).length;
                if(countMultiField > 0)
                {
                    let value = $(".full_inv_syn").val();
                    if(value=="Once")
                    {
                        makeTimePicker("23:00");
                    }
                    else 
                    {
                        makeTimePicker("12:00");    
                    }
                }
                //end multiField

                //set customMap section Tooltip Text	
                // let defaultSecItems = 'Select the below default values for your '+ $("#defaultSecItems").val();	
                // $("#chooseDefTooltip").attr('title',defaultSecItems.substring(0,defaultSecItems.length - 2));	
                if (type == 2) 
                {
                    $(".btnResetMapping2").html('Fetch Latest Data');
                }

                //check user Access for mapping check from Active integration normal/refresh
                if (type == 1 || type == 3)
                {
                    checkUserAccess();
                }
                //check multiwarehouse mapping
                mappingSwitchAction('callByMappingReload')

            }
            //end success block
        },
        error: function (jqXHR, textStatus, errorThrown) {
            hideOverlay();
            if (jqXHR.status == 500) {
              toastr.error('Internal error: ' + jqXHR.responseText);
            } else {
              toastr.error('Unexpected error Please try again.');
            }
        }

    });
}

//load uploaded image preview
function previewThumbailFromUrl(opts) {
    var contentServerPath = $("#contentServerPath").val();
    let imgDropzone = Dropzone.forElement("." + opts.selector);
    let extOfFile =  opts.imageURL.split('.').pop();
    let mockFile = {
      name: opts.fileName,
      size: 12345,
      accepted: true,
      kind: extOfFile
    };

    imgDropzone.files.push(mockFile);
    imgDropzone.emit("addedfile", mockFile);

    if (mockFile.kind=="csv") {
        $("#"+opts.selector).find(".dz-image img").attr("src", contentServerPath+"/public/esb_asset/icons/csv.png");
    }
    else if (mockFile.kind=="txt") {
        $("#"+opts.selector).find(".dz-image img").attr("src", contentServerPath+"/public/esb_asset/icons/text.png");
    }
    else if (mockFile.kind=="png" || mockFile.kind==".jpg" || mockFile.kind==".jpeg") {
        $("#"+opts.selector).find(".dz-image img").attr("src", contentServerPath+"/public/esb_asset/icons/image.png");
    }
    else
    {
        $("#"+opts.selector).find(".dz-image img").attr("src", contentServerPath+"/public/esb_asset/icons/file.png");
    }
    
}

function storeMapping(type = 1)
{

    var form_data = new FormData();

    let validErrStatus=0;
    var fixedUrl = $("#AjaxCallUrl").val();
    var csrf_token = $('meta[name="csrf-token"]').attr('content');
    var userIntegId = $("#input_IntegPlateformId").val();
    let formData = [];
    let identData = [];
    let extensiveIdentData = [];

    formData += '{ "data" : [';
    extensiveIdentData += '{ "extensiveIdentData" : [';

    //first validate connection data if if mapping save from connection setting
    if(type==2)
    {
        let dc = $('#destination_platform').val();
        let sc = $('#source_platform').val();
        validateConnections(dc,sc)
    }
    //validate Identity Fields
    var countIdentMapField = $("#MappingIndentFCount").val();
    if (countIdentMapField > 0) {
        let identity_response = validateIdentity(validErrStatus,identData);
        identData += identity_response;
    }
    //end identity validations

    //loop selected extensive identity mapping data
    const listExtensiveIdentityMapArr = JSON.parse($("#list_extensive_identity_mapping").val());
    $.each(listExtensiveIdentityMapArr,function(key,pobjName){
        let countExtensiveIdentMapField = $('#Mapping_'+pobjName+'_Count').val();
        if (countExtensiveIdentMapField > 0) {
            extensiveIdentData += getExtensiveIdentityMappingData(validErrStatus,pobjName)
        }
    })

    

    //one to one with +- option mapping validations
    const Many2ManyValidationArr = JSON.parse($("#Many2ManyValidationArr").val());
    $.each(Many2ManyValidationArr,function(key,pobjName){
        //make dynamic mapping count
        let countMapField = $('#Mapping'+pobjName+'Count').val();
        //console.log(countMapField)
        if (countMapField > 0) {
            let AvailSides = JSON.parse($('#Avail'+pobjName+'Sides').val());
            //append clone select data
                let sourceFieldID=null; let destFieldID=null; let editId=null; let pfwfrID=null; let platform_object_id=null; let data_map_type=null;
                let sourceInputType=null; let destInputType=null;
                let source_linked_table=null;
                let dest_linked_table=null;
                let mapping_type="regular";
                if(AvailSides.length==1)
                {
                    mapping_type ="default";
                }

            for (let i = 0; i <= countMapField - 1; i++) {
                if (i == 0) { i = ""; }

            if(AvailSides.includes("source"))
            {
                sourceInputType = $('.'+pobjName+'_sourceSelect'+i).prop("type");

                sourceFieldID = $('.'+pobjName+'_sourceSelect'+i).val();
                // editId = $('.'+pobjName+'_sourceSelect'+i).find('option:selected').attr("name");
                editId = $('.'+pobjName+'_sourceSelect'+i).attr('data-editId'); 

                //get linked table
                source_linked_table = $('.'+pobjName+'_sourceSelect'+i).attr('data-linked_table'); 
                
                if(editId==undefined)
                {
                    editId="";
                }
                pfwfrID = $('.'+pobjName+'_sourceSelect'+i).attr("name");
                platform_object_id = $('.'+pobjName+'_sourceSelect'+i).attr("alt");
                if($('.'+pobjName+'_sourceSelect'+i).prop('required')){

                if (sourceFieldID == "") {
                    $('.'+pobjName+'_sourceSelect'+i).css({ "border-color": "red", "border-width": "1px", "border-style": "solid" });
                    validErrStatus=1;
                    $('html, body, .card-body').animate({ scrollTop: $('.'+pobjName+'_sourceSelect'+i).offset().top-150 }, 200);
                    return false;
                }
                else {
                    $('.'+pobjName+'_sourceSelect'+i).css({ "border-color": "#D8D6DE", "border-width": "1px", "border-style": "solid" });
                  }

                }
            }
            if(AvailSides.includes("destination"))
            {
                destInputType = $('.'+pobjName+'_destSelect'+i).prop("type");
      
                destFieldID = $('.'+pobjName+'_destSelect'+i).val();
                // editId = $('.'+pobjName+'_destSelect'+i).find('option:selected').attr("name");
                editId = $('.'+pobjName+'_destSelect'+i).attr('data-editId'); 

                //get linked table
                dest_linked_table = $('.'+pobjName+'_destSelect'+i).attr('data-linked_table');

                if(editId==undefined)
                {
                    editId="";
                }
                pfwfrID = $('.'+pobjName+'_destSelect'+i).attr("name");
                platform_object_id = $('.'+pobjName+'_destSelect'+i).attr("alt");
                if($('.'+pobjName+'_destSelect'+i).prop('required')){

                if (destFieldID == "") {
                    $('.'+pobjName+'_destSelect'+i).css({ "border-color": "red", "border-width": "1px", "border-style": "solid" });
                    validErrStatus=1;
                    $('html, body, .card-body').animate({ scrollTop: $('.'+pobjName+'_destSelect'+i).offset().top-150 }, 200);
                    return false;
                }
                else {
                    $('.'+pobjName+'_destSelect'+i).css({ "border-color": "#D8D6DE", "border-width": "1px", "border-style": "solid" });
                }
              }

            }

            //handle data map type
            if(sourceInputType && destInputType)
            {
                if(sourceInputType=="select-one" && destInputType=="select-one")
                {
                    data_map_type="object";

                    //check again for field mapping
                    if( (source_linked_table && dest_linked_table) =="platform_fields" ) {
                        data_map_type="field";
                    } else if( source_linked_table=="platform_fields" && dest_linked_table !="platform_fields") {
                        data_map_type="field_and_object";
                    } else if( dest_linked_table =="platform_fields" && source_linked_table !="platform_fields" ) {
                        data_map_type="object_and_field";
                    }
                    
                }
                else if(sourceInputType !="select-one" && destInputType=="select-one")
                {
                    data_map_type="custom_and_object";

                    //check again for field mapping
                    if( (source_linked_table || dest_linked_table) =="platform_fields" ) {
                        data_map_type="custom_and_field";
                    }

                }
                else if(sourceInputType =="select-one" && destInputType !="select-one")
                {
                    data_map_type="object_and_custom";

                    //check again for field mapping
                    if( (source_linked_table || dest_linked_table) =="platform_fields" ) {
                        data_map_type="field_and_custom";
                    }
                }
                else
                {
                    data_map_type="object";
                }
            }
            else
            {
                if(sourceInputType)
                {
                    data_map_type = (sourceInputType=="select-one")? 'object' : 'custom';
                } else if(destInputType)
                {
                    data_map_type = (destInputType=="select-one")? 'object' : 'custom';
                }
                else
                {
                    data_map_type="object";
                }
            }
            //end handle data map type
            

            if((sourceFieldID) || (destFieldID))
            {
                if(data_map_type=="object" || data_map_type=="field")
                {
                    formData += `{"data_map_type":"${data_map_type}","platform_object_id":"${platform_object_id}","mapping_type":"${mapping_type}","platform_workflow_rule_id":"${pfwfrID}","source_row_id":"${sourceFieldID}","destination_row_id":"${destFieldID}","custom_data":"","status":1,"editId":"${editId}"},`;
                }
                else if(data_map_type=="object_and_custom" || data_map_type=="field_and_custom")
                {
                    formData += `{"data_map_type":"${data_map_type}","platform_object_id":"${platform_object_id}","mapping_type":"${mapping_type}","platform_workflow_rule_id":"${pfwfrID}","source_row_id":"${sourceFieldID}","destination_row_id":"","custom_data":"${destFieldID}","status":1,"editId":"${editId}"},`;
                }
                else if(data_map_type=="custom_and_object" || data_map_type=="custom_and_field")
                {
                    formData += `{"data_map_type":"${data_map_type}","platform_object_id":"${platform_object_id}","mapping_type":"${mapping_type}","platform_workflow_rule_id":"${pfwfrID}","source_row_id":"","destination_row_id":"${destFieldID}","custom_data":"${sourceFieldID}","status":1,"editId":"${editId}"},`;
                }
            
            }

            }
        }
    })
    //End one to one

    //start field mapping /with new update as OpenedRegularMapping where will not give option for add & remove will show all data like old field mapping
    let platform_object_id = null;
    let data_map_type = 'object';
    var countFMsection = $("#TotalFieldMappingSection").val();
    if (countFMsection > 0) {
        for (let i = 1; i <= countFMsection; i++) {
            let CountFieldInSection = $("#totalFieldInSec" + i).val();
            let sourceFieldID=""; let destFieldID="";
            for (let j = 1; j <= CountFieldInSection; j++) {
                let s = `.Section${i} > .sourceField${j}`;
                sourceFieldID = $(s).val();
                let editId = $(s).attr("data-editId");
                platform_object_id = $(s).attr("alt");
                data_map_type = $(s).attr("data_map_type");
                let d = `.Section${i} > .destField${j}`;
                destFieldID = $(d).val();
                let pfwfrID = $("#totalFieldInSec" + i).attr('name');

                if($(d).prop('required')){

                    if (destFieldID == "") {
                        $(d).css({ "border-color": "red", "border-width": "1px", "border-style": "solid" });
                        validErrStatus=1;
                        $('html, body, .card-body').animate({ scrollTop: $(d).offset().top-150 }, 200);
                        return false;
                    }
                    else {
                        $(d).css({ "border-color": "#D8D6DE", "border-width": "1px", "border-style": "solid" });

                    }
                }

                if((sourceFieldID) && (destFieldID))
                {
                    formData += `{"data_map_type":"${data_map_type}","platform_object_id":"${platform_object_id}","mapping_type":"regular","platform_workflow_rule_id":"${pfwfrID}","source_row_id":"${sourceFieldID}","destination_row_id":"${destFieldID}","custom_data":"","status":1,"editId":"${editId}"},`;
                }

            }
        }
    }
    //end field mapping validation

    //Start Default mapping validation
    const validationArray = JSON.parse($("#validationArray").val());
    var validRegex = /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9-]+(?:\.[a-zA-Z0-9-]+)*$/;
    $.each(validationArray,function(key,pobjName){
        //call validation for each pobjName / key
        var countObject = $("#count"+pobjName).val();
        if(countObject > 0)
        {
            var AvailObjectSides = JSON.parse($('#Avail'+pobjName+'Sides').val());
            //console.log(AvailObjectSides);
            let sourceFieldID=null; let destFieldID=null; let editId=null; let pfwfrID=null;let platform_object_id=null; let data_map_type=null; 
            let custom_data=null; let custom_input_type="";
            let mapping_type="regular";
            if(AvailObjectSides.length==1)
            {
                mapping_type ="default";
            }
            if(AvailObjectSides.includes("source"))
            {
                sourceFieldID = $('.'+pobjName+'_sourceSelect').val();
                platform_object_id = $('.'+pobjName+'_sourceSelect').attr("alt");
                pfwfrID = $('.'+pobjName+'_sourceSelect').attr("name");
                data_map_type = $('.'+pobjName+'_sourceSelect').attr('data-data_map_type'); 
                custom_input_type = $('.'+pobjName+'_sourceSelect').attr('type'); 
                editId = $('.'+pobjName+'_sourceSelect').attr('data-editId');
               
                //if it has props required
                if($('.'+pobjName+'_sourceSelect').prop('required')){
                    if (sourceFieldID == "") {
                        $('.'+pobjName+'_sourceSelect').css({ "border-color": "red", "border-width": "1px", "border-style": "solid" });
                        validErrStatus=1;
                        $('html, body, .card-body').animate({ scrollTop: $('.'+pobjName+'_sourceSelect').offset().top-150 }, 200);
                        return false;
                    }
                    else {
                        $('.'+pobjName+'_sourceSelect').css({ "border-color": "#D8D6DE", "border-width": "1px", "border-style": "solid" });
                          //check email validation for custom field with input type email
                        if(custom_input_type=="email")
                        {
                            if (!sourceFieldID.match(validRegex)) {
                                toastr.error('Please Enter Valid Email Address');
                                validErrStatus=1;
                                return false;
                            } 
                        }
                        //end email validation
                    }
                }

                if(data_map_type=="custom" || data_map_type=="timezone")
                {
                    custom_data = sourceFieldID;
                }

            
            }
            if(AvailObjectSides.includes("destination"))
            {
                destFieldID = $('.'+pobjName+'_destSelect').val();
                platform_object_id = $('.'+pobjName+'_destSelect').attr("alt");
                pfwfrID = $('.'+pobjName+'_destSelect').attr("name");
                data_map_type = $('.'+pobjName+'_destSelect').attr('data-data_map_type'); 
                custom_input_type = $('.'+pobjName+'_destSelect').attr('type'); 
                editId = $('.'+pobjName+'_destSelect').attr('data-editId');

                //required if set in rule
                if($('.'+pobjName+'_destSelect').prop('required')){

                if (destFieldID == "") {
                    $('.'+pobjName+'_destSelect').css({ "border-color": "red", "border-width": "1px", "border-style": "solid" });
                    validErrStatus=1;
                    $('html, body, .card-body').animate({ scrollTop: $('.'+pobjName+'_destSelect').offset().top-150 }, 200);
                    return false;
                }
                else {
                    $('.'+pobjName+'_destSelect').css({ "border-color": "#D8D6DE", "border-width": "1px", "border-style": "solid" });
                     //check email validation for custom field with input type email
                    if(custom_input_type=="email")
                    {
                        if (!destFieldID.match(validRegex)) {
                            toastr.error('Please Enter Valid Email Address');
                            validErrStatus=1;
                            return false;
                        } 
                    }
                    //end email validation
                }

             }
             if(data_map_type=="custom" || data_map_type=="timezone")
             {
                 custom_data = destFieldID;
             }

            }
            
            //append data for custom & object
            if(data_map_type=="custom" || data_map_type=="timezone")
            {
                formData += `{"data_map_type":"${data_map_type}","platform_object_id":"${platform_object_id}","mapping_type":"${mapping_type}","platform_workflow_rule_id":"${pfwfrID}","source_row_id":"","destination_row_id":"","custom_data":"${custom_data}","status":1,"editId":"${editId}"},`;
            }
            else
            {
                if((sourceFieldID) || (destFieldID))
                {
                    formData += `{"data_map_type":"${data_map_type}","platform_object_id":"${platform_object_id}","mapping_type":"${mapping_type}","platform_workflow_rule_id":"${pfwfrID}","source_row_id":"${sourceFieldID}","destination_row_id":"${destFieldID}","custom_data":"","status":1,"editId":"${editId}"},`;
                }
            }
            
        }

    })
    //Default mapping validation

    //Other to other mapping Validations
    const otherMapValidationArr = JSON.parse($("#otherMapValidationArr").val());
    $.each(otherMapValidationArr,function(key,pobjName){
       //order_warehouse_TO_sorder_location_dynemic > row
        let sourceFieldID=null; let destFieldID=null; let editId=null; let pfwfrID=null; let platform_object_id=null; let mapping_type="cross";
        let data_map_type=null;let sourceInputType=null; let destInputType=null;

       $("."+pobjName).find(".col-md-12").each(function(k,v){

            sourceFieldID = $( this ).find(".sourceSelect").val();
            // sourceInputType = $( this ).find(".sourceSelect").prop("type");
            sourceInputType = $( this ).find(".sourceSelect").attr("data-data_map_type");

            editId = $( this ).find(".sourceSelect").attr('data-editId');
            if(editId==undefined)
            {
                editId="";
            }

            if (sourceFieldID == "") {
                if($( this ).find(".sourceSelect").prop('required')){
                    $( this ).find(".sourceSelect").css({ "border-color": "red", "border-width": "1px", "border-style": "solid" });
                    validErrStatus=1;
                    $('html, body, .card-body').animate({ scrollTop: $( this ).find(".sourceSelect").offset().top-150 }, 200);
                    return false;
                }
                else
                {
                    $( this ).find(".sourceSelect").css({ "border-color": "#D8D6DE", "border-width": "1px", "border-style": "solid" });
                } 
            }
           
            destFieldID = $( this ).find(".destSelect").val();
            // destInputType = $( this ).find(".destSelect").prop("type");
            destInputType = $( this ).find(".destSelect").attr("data-data_map_type");

            if (destFieldID == "") {
                if($( this ).find(".destSelect").prop('required')){
                    $( this ).find(".destSelect").css({ "border-color": "red", "border-width": "1px", "border-style": "solid" });
                    validErrStatus=1;
                    $('html, body, .card-body').animate({ scrollTop: $( this ).find(".destSelect").offset().top-150 }, 200);
                    return false;
                }
                else
                {
                    $( this ).find(".destSelect").css({ "border-color": "#D8D6DE", "border-width": "1px", "border-style": "solid" });
                }   
            }

            //handle data map type
            if(sourceInputType && destInputType)
            {
                if(sourceInputType=="object" && destInputType=="object")
                {
                    data_map_type="object";

                } else if (sourceInputType=="field" && destInputType=="field") {
                    data_map_type="field";
                }
                else {
                    data_map_type = sourceInputType+'_and_'+destInputType;
                }
            }
            else
            {
                if(sourceInputType)
                {
                    data_map_type = (sourceInputType=="select-one")? 'object' : 'custom';
                }else if(destInputType)
                {
                    data_map_type = (destInputType=="select-one")? 'object' : 'custom';
                }
                else
                {
                    data_map_type="object";
                }
            }
            //end handle data map type

            if((sourceFieldID) && (destFieldID))
            {
                pfwfrID = $( this ).find(".sourceSelect").attr('name');
                platform_object_id = $( this ).find(".sourceSelect").attr('alt');

                if(data_map_type=="object" || data_map_type=="field" )
                {
                    formData += `{"data_map_type":"${data_map_type}","platform_object_id":"${platform_object_id}","mapping_type":"${mapping_type}","platform_workflow_rule_id":"${pfwfrID}","source_row_id":"${sourceFieldID}","destination_row_id":"${destFieldID}","custom_data":"","status":1,"editId":"${editId}"},`;
                }
                else if(data_map_type=="object_and_custom")
                {
                    formData += `{"data_map_type":"${data_map_type}","platform_object_id":"${platform_object_id}","mapping_type":"${mapping_type}","platform_workflow_rule_id":"${pfwfrID}","source_row_id":"${sourceFieldID}","destination_row_id":"","custom_data":"${destFieldID}","status":1,"editId":"${editId}"},`;
                }
                else if(data_map_type=="custom_and_object")
                {
                    formData += `{"data_map_type":"${data_map_type}","platform_object_id":"${platform_object_id}","mapping_type":"${mapping_type}","platform_workflow_rule_id":"${pfwfrID}","source_row_id":"","destination_row_id":"${destFieldID}","custom_data":"${sourceFieldID}","status":1,"editId":"${editId}"},`;
                }
                else if(data_map_type=="field_and_custom")
                {
                    formData += `{"data_map_type":"${data_map_type}","platform_object_id":"${platform_object_id}","mapping_type":"${mapping_type}","platform_workflow_rule_id":"${pfwfrID}","source_row_id":"${sourceFieldID}","destination_row_id":"","custom_data":"${destFieldID}","status":1,"editId":"${editId}"},`;
                }
                else if(data_map_type=="custom_and_field")
                {
                    formData += `{"data_map_type":"${data_map_type}","platform_object_id":"${platform_object_id}","mapping_type":"${mapping_type}","platform_workflow_rule_id":"${pfwfrID}","source_row_id":"","destination_row_id":"${destFieldID}","custom_data":"${sourceFieldID}","status":1,"editId":"${editId}"},`;
                }
                else if(data_map_type=="state_and_object")
                {
                    formData += `{"data_map_type":"${data_map_type}","platform_object_id":"${platform_object_id}","mapping_type":"${mapping_type}","platform_workflow_rule_id":"${pfwfrID}","source_row_id":"${sourceFieldID}","destination_row_id":"${destFieldID}","custom_data":"","status":1,"editId":"${editId}"},`;
                }
                
            }
       })
       
    });
    //end other to other validation

    //multiselect mappings Validations
    const MsValidationArr = JSON.parse($("#MsValidationArr").val());
    $.each(MsValidationArr,function(key,pobjName){
        //make dynamic mapping count
        let countMapField = $('#Mapping'+pobjName+'Count').val();
        //console.log(countMapField)
        if (countMapField > 0) {
            let AvailSides = JSON.parse($('#Avail'+pobjName+'Sides').val());

            //append clone select data
            let sourceFieldID=null; let destFieldID=null; let editId=null; let pfwfrID=null; let platform_object_id=null;
            let mapping_type="regular"; let data_map_type = null;

            for (let i = 0; i <= countMapField - 1; i++) {
                if (i == 0) { i = ""; 
            }

            if(AvailSides.includes("source"))
            {
                sourceFieldID = $('.'+pobjName+'_sourceSelect'+i).val();
                editId = $('.'+pobjName+'_sourceSelect'+i).find('option:selected').attr("name");
                data_map_type = $('.'+pobjName+'_sourceSelect'+i).attr("data-data_map_type");

                if(editId==undefined)
                {
                    editId="";
                }
                pfwfrID = $('.'+pobjName+'_sourceSelect'+i).attr("name");
                platform_object_id = $('.'+pobjName+'_sourceSelect'+i).attr("alt");

                if (sourceFieldID == "") {
                    if($('.'+pobjName+'_sourceSelect'+i).prop('required')){
                        $('.'+pobjName+'_sourceSelect'+i).next("span").css({ "border-color": "red", "border-width": "1px", "border-style": "solid" });
                        validErrStatus=1;
                        $('html, body, .card-body').animate({ scrollTop: $('.'+pobjName+'_sourceSelect'+i).offset().top-150 }, 200);
                        return false;
                    } 
                    else
                    {
                        $('.'+pobjName+'_sourceSelect'+i).next("span").css({ "border-color": "#D8D6DE", "border-width": "1px", "border-style": "solid" });
                    }
                }
                else {
                        $.each(sourceFieldID, function (k, v) {
                            sourceField = v
                            formData += `{"data_map_type":"${data_map_type}","platform_object_id":"${platform_object_id}","mapping_type":"${mapping_type}","platform_workflow_rule_id":"${pfwfrID}","source_row_id":"${sourceField}","destination_row_id":"","custom_data":"","status":1,"editId":""},`;
                         })

                  }
                
            }
            if(AvailSides.includes("destination"))
            {
                destFieldID = $('.'+pobjName+'_destSelect'+i).val();
                editId = $('.'+pobjName+'_destSelect'+i).find('option:selected').attr("name");
                data_map_type = $('.'+pobjName+'_destSelect'+i).attr("data-data_map_type");
                if(editId==undefined)
                {
                    editId="";
                }
                pfwfrID = $('.'+pobjName+'_destSelect'+i).attr("name");
                platform_object_id = $('.'+pobjName+'_destSelect'+i).attr("alt");
                
                if (destFieldID == "") {
                    if($('.'+pobjName+'_destSelect'+i).prop('required')){
                        $('.'+pobjName+'_destSelect'+i).next("span").css({ "border-color": "red", "border-width": "1px", "border-style": "solid" });
                        validErrStatus=1;
                        $('html, body, .card-body').animate({ scrollTop: $('.'+pobjName+'_destSelect'+i).offset().top-150 }, 200);
                        return false;
                    }
                    else
                    {
                        $('.'+pobjName+'_destSelect'+i).next("span").css({ "border-color": "#D8D6DE", "border-width": "1px", "border-style": "solid" });
                    }
                   
                }
                else {
                    $.each(destFieldID, function (k, v) {
                        destField = v
                        formData += `{"data_map_type":"${data_map_type}","platform_object_id":"${platform_object_id}","mapping_type":"${mapping_type}","platform_workflow_rule_id":"${pfwfrID}","source_row_id":"","destination_row_id":"${destField}","custom_data":"","status":1,"editId":""},`;
                     })
                }
              

            }
            
            }
        }
    })
    //End multiselect validation


    //start sync start date
    let SynStartDate=""; let pfwfrID=""; let synStartDataTimeArr = [];
    const ssValidationArr = JSON.parse($("#ssValidationArr").val());
    $.each(ssValidationArr,function(key,pobjName){
        let countMapField = $('#Mapping'+pobjName+'Count').val();
        if (countMapField > 0) {
            SynStartDate = $('.'+pobjName).val();
            pfwfrID = $('.'+pobjName).attr("data-wfrId");
                if (SynStartDate == "") {
                    if($('.'+pobjName).prop('required')){
                        $('.'+pobjName).css({ "border-color": "red", "border-width": "1px", "border-style": "solid" });
                        validErrStatus=1;
                        $('html, body, .card-body').animate({ scrollTop: $("#fp-date-time").offset().top-150 }, 200);
                        return false;
                    }
                    else
                    {
                        $('.'+pobjName).css({ "border-color": "#D8D6DE", "border-width": "1px", "border-style": "solid" });
                    }
                    
                } 
                else
                {
                    synStartDataTimeArr.push({SynStartDate,pfwfrID});
                }
            
            //end required validation
            //console.log(synStartDataTimeArr);
        }
    })
    //end sync start date

    let fileMappingData = []; 
    fileMappingData += '{ "data" : [';
    let fileMapValidationArr = JSON.parse($("#fileMapValidationArr").val());
    let fieldMapCount = 0;
    $.each(fileMapValidationArr,function(key,pobjName){
        //validate mapping files
        if(window['myDropzone_'+pobjName].files.length > 0)
        {
            fieldMapCount ++;

            let pfwfrID = $("#"+pobjName).attr("data-wfrId");
            let platform_object_id = $("#"+pobjName).attr("data-pltObjId");
            let platform = $("#"+pobjName).attr("data-platform");
            let custom_data = "files_"+pobjName;
            let editId = $("#"+pobjName).attr("data-editId");
            if(editId==undefined)
            {
                editId="";
            }
            for (i = 0; i < window['myDropzone_'+pobjName].files.length; i++) {
               file = window['myDropzone_'+pobjName].files[i];
               form_data.append("files_"+pobjName+"[]", file);
            }
            
            if(fieldMapCount !=1)
            {
                fileMappingData += `,`;
            }
            fileMappingData += `{"data_map_type":"custom","platform_object_id":"${platform_object_id}","mapping_type":"default","platform_workflow_rule_id":"${pfwfrID}","platform":"${platform}","custom_data":"${custom_data}","status":1,"editId":"${editId}"}`;
        }
        else
        {
            let fileValidation = $("#"+pobjName).attr("data-validation");
            if(fileValidation =="required"){
            //if selected mapping is required
            toastr.error("Mapping File must be seleted");
            validErrStatus=1;
            return;
            }
        }
        //end 
    })

    //mapWithMultiField with switch on/off
    let multiFieldMapValidationArr = JSON.parse($("#multiFieldMapValidationArr").val());
    $.each(multiFieldMapValidationArr,function(key,pobjName){
        if(pobjName=="full_inventory_sync")
        {
            let custom_data=""; let full_inv_syn="";let full_inv_syn_dt=""; let status=1;
            let platform_object_id = $('.'+pobjName).find('.full_inv_syn').attr("data-pltObjId");
            let pfwfrID = $('.'+pobjName).find('.full_inv_syn').attr("data-wfrId");
            let editId = $('.'+pobjName).find('.full_inv_syn').attr("data-editId"); 
            if(editId==undefined)
            {
                editId="";
            }
            let reqValid = $(".full_inv_syn").attr("data-validation"); 

            full_inv_syn = $(".full_inv_syn").val();
            full_inv_syn_dt = $(".full_inv_syn_dt").val();
            if(reqValid)
            {
                if(full_inv_syn=="")
                {
                    $(".full_inv_syn").css({ "border-color": "red", "border-width": "1px", "border-style": "solid" });
                    $('html, body, .card-body').animate({ scrollTop: $(".full_inv_syn").offset().top-150 }, 200);
                    validErrStatus=1;
                    return false;
                }
                else
                {
                    $(".full_inv_syn").css({ "border-color": "#D8D6DE", "border-width": "1px", "border-style": "solid" });
                }
                if(full_inv_syn_dt=="")
                {
                    $(".full_inv_syn_dt").css({ "border-color": "red", "border-width": "1px", "border-style": "solid" });
                    $('html, body, .card-body').animate({ scrollTop: $(".full_inv_syn_dt").offset().top-150 }, 200);
                    validErrStatus=1;
                    return false;
                }
                else
                {
                    $(".full_inv_syn_dt").css({ "border-color": "#D8D6DE", "border-width": "1px", "border-style": "solid" });
                }
            }  
            if(full_inv_syn && full_inv_syn_dt)
            {
                custom_data = full_inv_syn+"|"+full_inv_syn_dt;
                formData += `{"data_map_type":"custom","platform_object_id":"${platform_object_id}","mapping_type":"default","platform_workflow_rule_id":"${pfwfrID}","source_row_id":"","destination_row_id":"","custom_data":"${custom_data}","status":"${status}","editId":"${editId}"},`;
            }
        }

    })



    //multiWarehouse_switch_count
    let multi_wh_switch_status = 1; 
    let multiWarehouse_switch_count = $(".multiWarehouse_switch_count").val();
    if(multiWarehouse_switch_count==1)
    {
        var ischecked_mws = $(".multiWarehouse_switch").is(':checked');
        if (!ischecked_mws) {
            multi_wh_switch_status = 0;
        }
        let platform_object_id = $(".multiWarehouse_switch").attr("data-pltObjId");
        let editId = $(".multiWarehouse_switch").attr("data-editId"); 
        if(editId==undefined)
        {
            editId="";
        }
        let status=1;
        formData += `{"data_map_type":"custom","platform_object_id":"${platform_object_id}","mapping_type":"default","platform_workflow_rule_id":"","source_row_id":"","destination_row_id":"","custom_data":"${multi_wh_switch_status}","status":"${status}","editId":"${editId}"},`;   

    }


    //handle data retention policy
    let drp_section_status = $(".data_retention_policy_status").val();
    let drp_period = 0;
    let drp_status = 0;

    if(drp_section_status==1)
    {   
        var ischecked = $("#data_retention_switch").is(':checked');
        if (!ischecked) {
            drp_status = 0;
        }
        else {
            drp_status = 1;
            drp_period = $(".data_retention_period").val();
        }
        
    }
    
    fileMappingData += ']}';
    form_data.append("fileMappingData", fileMappingData);

    formData += ']}';
    extensiveIdentData += ']}';

    //append fileMappingData
    

    //if validation error
    if(validErrStatus==1)
    {
        toastr.error('Mapping Fields must be Required.');
        return;
    }
    
    
    //unique validation test before save
    const DataValidate = formData.replace(",]}", "]}");
    // console.log(DataValidate);

    const values = JSON.parse(DataValidate);
    var hasDupsObjects = function(array) {
        return array.map(function(value) {
            // console.log(value)
        //return value.source_row_id + value.destination_row_id + value.platform_object_id + value.mapping_type
        return value.platform_object_id + value.mapping_type  + value.source_row_id + value.destination_row_id + value.platform_workflow_rule_id
        }).some(function(value, index, array) {
            return array.indexOf(value) !== array.lastIndexOf(value);
            })
    }
    let x = hasDupsObjects(values.data);
    if(x==true)
    {
        toastr.error('Mapping Fields must be Unique.');
        return;
    }
    //end unique validations

    //Append mapping data in form
    form_data.append("_token", csrf_token);
    form_data.append("userIntegId", userIntegId);
    form_data.append("data", formData.replace(",]}", "]}"));
    form_data.append("identData", identData);

    //include extensive identity mappings data
    form_data.append("extensiveIdentData", extensiveIdentData.replace(",]}", "]}"));

    form_data.append("SynStartDate", JSON.stringify(synStartDataTimeArr));
    form_data.append("drp_status", drp_status);
    form_data.append("drp_period", drp_period);
    //end append form

    //Type for save mapping from connection setting page
    if(type==2)
    {
        var wfid = $('.connectionId').attr('data-id');
        let dc = $('#destination_platform').val();
        let sc = $('#source_platform').val();
        //append more required things in form data
        form_data.append("_token", csrf_token);
        form_data.append("wfid", wfid);
        form_data.append("selected_sc_account_id", sc);
        form_data.append("selected_dc_account_id", dc);

        //case 1 if user come from connection setting
        SaveConnection(form_data);

    }
    else
    {

        // for (var value of form_data.values()) {
        //     console.log(value);
        // }


        showOverlay()
        $.ajax({
            type: 'post',
            url: fixedUrl + '/storeMapping',
            processData:false,
            data : form_data,
            dataType : 'json',
            contentType : false,
            async :true,
            success: function (data) {
                hideOverlay()
                if(data.status_code==1)
                {
                    toastr.success(data.status_text);
                    //reload Mapping Data after Successfull Store Mapping
                    openMapping(type = 1,isConnectionPage=0)
                }
                else
                {
                    toastr.error(data.status_text);
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                hideOverlay();
                if (jqXHR.status == 500) {
                  toastr.error('Internal error: ' + jqXHR.responseText);
                } else {
                  toastr.error('Unexpected error Please try again.');
                }
            }
        });

    }

}

function getFlowData() {
    var userIntegId = $('#input_IntegPlateformId').val();
    var fixedUrl = $("#AjaxCallUrl").val();
    showOverlay()
    $.ajax({
        type: 'post',
        url: fixedUrl + '/flows_list/' + userIntegId,
        data: {
            '_token' : $('meta[name="csrf-token"]').attr('content'),
            'userIntegId': userIntegId,
        },
        success: function (data) {
            let is_transactional_flow = false;
            $.each(data.data, function (k, v) {
                if(v.isTransFlow){
                    is_transactional_flow = true;
                }
            });
            hideOverlay()
            let flowData = "";
            let flowData2 = "";
            let checkStatus = "";
            let i=1;
            let j=1;
            let status='';
            let tooltip_text = "";
            $.each(data.data, function (k, v) {
                if (v.uwfrStatus == 1) { checkStatus = "checked"; } else { checkStatus = ""; }
                if(v.IsAllDataFetched =='Pending'){
                    status= '<span class="right badge badge-info" id="badge" >'+v.IsAllDataFetched+'</span>';
                }else if(v.IsAllDataFetched == 'Completed'){
                    status= '<span class="right badge badge-success" data-toggle="tooltip" title="Initial Sync Completed"id="badge" >'+v.IsAllDataFetched+'</span>';
                }else if(v.IsAllDataFetched == 'Inprocess'){
                    status= '<span class="right badge badge-warning" data-toggle="tooltip" title="Initial Sync In-process" id="badge" >Processing</span>';
                }
                if(v.tooltip_text){
                    tooltip_text = `<span data-toggle="tooltip" data-placement="right" title="${v.tooltip_text}" style="cursor:pointer"><i class="fa fa-question-circle"></i></span>`;
                } else {
                    tooltip_text = "";
                }
    

                

                if(v.isTransFlow){
                    flowData2 += `<tr>
                    <td>${j}</td>
                    <td>${v.destinationEvent}  ${tooltip_text}</td>
                    <td>${v.sourcePlt}&nbsp;&nbsp;<i class="fa fa-arrow-right" aria-hidden="true"></i>&nbsp;&nbsp;${v.destPlt}</td>
                    <td>${v.last_update}</td>`;
                }else{
                    flowData += `<tr>
						<td>${i}</td>
						<td>${v.destinationEvent}  ${tooltip_text}</td>
                        <td>${v.sourcePlt}&nbsp;&nbsp;<i class="fa fa-arrow-right" aria-hidden="true"></i>&nbsp;&nbsp;${v.destPlt}</td>
						<td>${v.last_update}</td>`;
                }
                
                
                if(data.modify == 1){
                    if(v.isTransFlow){
                        flowData2 += `<td><span data-toggle="tooltip" data-placement="left" data-original-title="Toggle each desired integration flow to “On” to activate. Toggle back to “Off” to deactivate the flow."><div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input" id="customSwitch${v.pfwfrID}" onClick="switchAction(${v.pfwfrID},${userIntegId},${v.isTransFlow})" ${checkStatus}><label class="custom-control-label" for="customSwitch${v.pfwfrID}"></label></div></span></td>`;
                    }else{
                        flowData += `<td><span data-toggle="tooltip" data-placement="left" data-original-title="Toggle each desired integration flow to “On” to activate. Toggle back to “Off” to deactivate the flow."><div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input" id="customSwitch${v.pfwfrID}" onClick="switchAction(${v.pfwfrID},${userIntegId},${v.isTransFlow})" ${checkStatus}><label class="custom-control-label" for="customSwitch${v.pfwfrID}"></label></div></span></td>`;
                    }
                    
                }
                else{
                    
                    if(v.isTransFlow){
                        flowData2 += `<td></td>`;
                    }else{
                        flowData += `<td></td>`;
                    }
                }
                if(v.isTransFlow){
                    flowData2 += `</tr>`;
                    j++;
                }else{
                    flowData += `</tr>`;
                    i++;
                }
            });
            

            let transactionalFlow1 =[];
            if(flowData !=""){
                let lable1 = '';
                if(is_transactional_flow){
                    lable1 = `<h6 class="text-center">SYNC PRE GO LIVE</h6>`;
                }
                transactionalFlow1 +=`<table class="table">
                <thead>
                    ${lable1}
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">Workflow</th>
                        <th scope="col">Direction</th>
                        <th scope="col">Last Sync</th>
                        <th scope="col">OFF/ON</th>
                    </tr>
                </thead>
                <tbody>
                ${flowData}
                </tbody>
            </table>`
            }

            let transactionalFlow2 =[];
            if(flowData2 !=""){
                transactionalFlow2 +=`<table class="table">
                <thead>
                    <h6 class="text-center">SYNC DURING GO LIVE <i class="fa fa-info-circle" data-toggle="tooltip" data-placement="right" data-original-title="Before switching on the following flows, kindly ensure that the Pre-Go Live items have finished syncing in the logs. Failure to do so could cause a lot of failures in the logs for below flows." style="cursor: pointer;"></i></h6>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">Workflow</th>
                        <th scope="col">Direction</th>
                        <th scope="col">Last Sync</th>
                        <th scope="col">OFF/ON</th>
                    </tr>
                </thead>
                <tbody>
                ${flowData2}
                </tbody>
            </table>`
            }
            
            document.getElementById('transactionalFlow1').innerHTML = transactionalFlow1;
            document.getElementById('transactionalFlow2').innerHTML = transactionalFlow2;

            if(data.data.length==0)
            {
                let emptyData=`<table class="table"><tr><td colspan="4" style="text-align:center;">No Connection Found</td></tr></table>`;
                document.getElementById('transactionalFlow1').innerHTML = emptyData;
                document.getElementById('transactionalFlow2').innerHTML = emptyData;
            }


           


        },
        error: function (jqXHR, textStatus, errorThrown) {
            hideOverlay();
            if (jqXHR.status == 500) {
              toastr.error('Internal error: ' + jqXHR.responseText);
            } else {
              toastr.error('Unexpected error Please try again.');
            }
        }
    });
}

function getConnectionData() {
    // showOverlay()
    var activeTabData = $("#activeTabData").val();
    var userIntegId = $('#input_IntegPlateformId').val();
    var fixedUrl = $("#AjaxCallUrl").val();
    var contentServerPath = $("#contentServerPath").val();
    let currentTimezone = "+00:00";
    if (localStorage.hasOwnProperty("current_time_zone"))
    {
        currentTimezone = localStorage.getItem("current_time_zone");
    }
    $.ajax({
        type: 'get',
        url: fixedUrl + '/connections/' + userIntegId,
        data: {
            'currentTimezone': currentTimezone,
        },
        success: function (data) {
            let connectionData = [];
            let checkStatus = ""; action = "";
            let i=1;
            let pltImage = "";
        
            $.each(data.data, function (k, v) {
                if(i==1){
                    pltImage = data.sourcePltImg;
                } else {
                    pltImage = data.destPltImg;
                }
                if (v.status == 1) {
                    action = "";
                    action =`<button type="button" class="btn btn-primary btn-sm disconnect secondary-btn-style" data-id='${v.id}' data-platformid='${v.platform_id}' data-platform_name='${v.account_name}'><i class="fa fa-chain-broken" aria-hidden="true"></i> Disconnect</button>`;
                    checkStatus = `<div class="row"><div style="width:15px;height:15px;background-color:#81C926;border-radius:100%;margin-top:2px"></div>&nbsp;&nbsp;&nbsp;Connected</div>`;
                }
                else {
                    action = `<a href="${fixedUrl}/connection-settings/${userIntegId}"><button type="button" class="btn btn-success btn-sm primary-btn-style"><i class="fa fa-plug" aria-hidden="true"></i> Connect</button></a>`;
                    checkStatus = `<div class="row"><div style="width:15px;height:15px;background-color:#999999;border-radius:100%;margin-top:2px"></div>&nbsp;&nbsp;&nbsp;Disconnected</div>`;
                }
                let Newenv_type = v.env_type.charAt(0).toUpperCase() + v.env_type.slice(1);
                connectionData += `<tr>
						<td><img class="connect_pltIcon" src="${pltImage}" height="50" width="50"></td>
						<td>${v.account_name.charAt(0).toUpperCase() + v.account_name.slice(1)}</td>
						<td>${checkStatus}</td>
						<td>${v.env_type.charAt(0).toUpperCase() + v.env_type.slice(1)}</td>
						<td>${v.updated_at}</td>`;

                if(data.modify == 1){
                    connectionData += `<td>${action}</td>`;
                }
                else{
                    connectionData += `<td></td>`;
                }
                connectionData += `</tr>`;

                i++;
            });
            document.getElementById('connectionDataSection').innerHTML = connectionData;
            if(data.data.length==0)
            {
                let emptyData=`<tr><td colspan="6" style="text-align:center;">No Connection Found</td></tr>`;
                document.getElementById('connectionDataSection').innerHTML = emptyData;
            }

        }
    });
}

//on off switch
function switchAction(pfwfrID, userIntegId, isTransFlow) {
    var csrf_token = $('meta[name="csrf-token"]').attr('content');
    let status = "";
    var fixedUrl = $("#AjaxCallUrl").val();
    

    var ischecked = $("#customSwitch" + pfwfrID).is(':checked');

    //handle user workflow check status...
    if (localStorage.hasOwnProperty("workflow_initial_data_sync_status_userIntegId_"+userIntegId)) 
    {
        localStorage.removeItem("workflow_initial_data_sync_status_userIntegId_"+userIntegId);
    }

    if (!ischecked) {
        status = 0;
        flow_oNoFF_confirmation("Confirm!","Are you sure that you want to off this  flow?",userIntegId,pfwfrID,status,fixedUrl,csrf_token);
    }
    else {
        status = 1;
        if(isTransFlow == 1){
            flow_oNoFF_confirmation("Confirm!","Before switching on the following flows, kindly ensure that the Pre-Go Live items have finished syncing in the logs. Failure to do so could cause a lot of failures in the logs for below flows.",userIntegId,pfwfrID,status,fixedUrl,csrf_token);
           
        }else{
            $.ajax({
                method: 'post',
                url: fixedUrl + '/updateIntegrationFlow',
                dataType: "json",
                data: {
                    'userIntegId': userIntegId,
                    'pfwfrID': pfwfrID,
                    'status': status,
                    '_token': csrf_token,
                },
                success: function (data) {
                    if(data.status_code==0){
                        toastr.error(data.status_text);
                        $('#connections-tab').trigger('click');
                        $('#customSwitch' + pfwfrID).prop('checked', false);
                    }
                    getFlowData();
                    getUserWorkflowStatus();
                    //window.location.reload();
                }
            });
        }
        
        
    }
    

    

};

//Mapping on Off switch for multi warehouse
function mappingSwitchAction(clickMethod) {
    let status = 0;
    var ischecked = $("#multiWarehouse_switch").is(':checked');
    var userIntegId  = $("#multiWarehouse_switch").attr('data-userIntegId');
    var integrationName = $("#multiWarehouse_switch").attr('data-integration');

    const one2one_multiwarehouseArray = JSON.parse($("#one2one_multiwarehouseArray").val());
    const default_multiwarehouseArray = JSON.parse($("#default_multiwarehouseArray").val());


    //if integration is bp-woo then show hide warehouse mapping
    if(integrationName=="Brightpearl_WooCommerce")
    {

        if (!ischecked) {

            status = 0;
            $.each(one2one_multiwarehouseArray,function(key,pobjName){
                $("."+pobjName).hide();
            })
            $.each(default_multiwarehouseArray,function(key,pobjName){
                $("."+pobjName).show();
            })
            $(".warehouse_plugins select option").removeAttr("selected");
            $(".warehouse_plugins").hide();

            //hide additional plus minus
            $(".warehouse_plugins").nextUntil('br').next().next().hide();
            $('fieldset[class^=inventory_warehouse-]').next().next().hide();


        }
        else {
            status = 1;
            $.each(one2one_multiwarehouseArray,function(key,pobjName){
                $("."+pobjName).show();
            })
            $.each(default_multiwarehouseArray,function(key,pobjName){
                $("."+pobjName).hide();
            })
    
            $(".warehouse_plugins").show();

            //show additional plus minus
            $(".warehouse_plugins").nextUntil('br').next().next().show();
            $('fieldset[class^=inventory_warehouse-]').next().next().show();

        }
    
    }
   

    if(clickMethod=="clickByUser") {
        let csrf_token = $('meta[name="csrf-token"]').attr('content');
        var fixedUrl = $("#AjaxCallUrl").val();
        $.ajax({
            type: 'POST',
            url: fixedUrl + '/inactive_warehouse_mapping',
            data: {
                'userIntegId': userIntegId,
                '_token': csrf_token,
                'status' : status
            },
            success: function (response) {
                if(response==1){
                    //reload mapping if old data remove
                    openMapping(type = 1,isConnectionPage=0);
                }
            }
        });

    }

};


function addwhFields(pobjName) {
    
    var oldCount = $('#Mapping'+pobjName+'Count').val();
    $('#Mapping'+pobjName+'Count').val(parseInt(oldCount) + 1);
    const tabId = $('.'+pobjName+'_D_MainItem').html();
    $('.'+pobjName+'_dynemic').append('<div class="row text-center mx-0 mb-1 justify-content-center align-items-center '+pobjName+'_DItem '+pobjName+'_Clone' + oldCount + '">' + tabId + '</div>');
    let newClass = '.'+pobjName+'_Clone' + oldCount;
    $(newClass + ' > div > div > div > .'+pobjName+'_sourceSelect').addClass(pobjName+'_sourceSelect' + oldCount);
    $(newClass + ' > div > div > div > .'+pobjName+'_sourceSelect' + oldCount).removeClass(pobjName+'_sourceSelect');
    $(newClass + ' > div > div > div > .'+pobjName+'_destSelect').addClass(pobjName+'_destSelect' + oldCount);
    $(newClass + ' > div > div > div > .'+pobjName+'_destSelect' + oldCount).removeClass(pobjName+'_destSelect');

    if(pobjName=="porder_shipping_method" || pobjName=="sorder_shipping_method")
    {
        $(newClass + ' > div > div > div > .zone_selection').addClass(pobjName+'_zone_selection' + oldCount);
        $('.'+pobjName+'_zone_selection' + oldCount + ' option').removeAttr('selected');
        let loadObj = $('.'+pobjName+'_zone_selection' + oldCount).attr('data-loadObj');
        $('.'+loadObj+ oldCount).html('<option value="">Select Shipping Method</option>');
    }


    let source_input_type = $('.'+pobjName+'_sourceSelect' + oldCount).attr('type'); 
    let dest_input_type = $('.'+pobjName+'_destSelect' + oldCount).attr('type'); 
    if(source_input_type !="select-one")
    {
        $('.'+pobjName+'_sourceSelect' + oldCount).val("");   
    }
    else
    {
        $('.'+pobjName+'_sourceSelect' + oldCount + ' option').removeAttr('selected');
    }
    if(dest_input_type !="select-one")
    {
        $('.'+pobjName+'_destSelect' + oldCount).val("");   
    }
    else
    {
        $('.'+pobjName+'_destSelect' + oldCount + ' option').removeAttr('selected');
    }

    $('.'+pobjName+'_sourceSelect' + oldCount).attr('data-editId','');
    $('.'+pobjName+'_destSelect' + oldCount).attr('data-editId','');

    $(newClass + ' > div > div > div > .addMappingField').css('display', 'none');
    $(newClass + ' > div > div > div > .removeMappingField').css('display', 'block');
}

function removeWhField(t,pobjName) {

    var oldCount = $('#Mapping'+pobjName+'Count').val();
    let newOldCount = parseInt(oldCount);
    var csrf_token = $('meta[name="csrf-token"]').attr('content');

    // var selectorName = $(t).parent().parent().parent().parent().parent().closest('div').attr('class').split(' ').pop();
    var selectorName = $(t).parent().parent().parent().parent().closest('div').attr('class').split(' ').pop();

    if (selectorName == pobjName+'_D_MainItem') {
        toastr.error('Root Mapping Selector Can not be deleted!');
    }
    else {
        var cloneId = selectorName.charAt(selectorName.length - 1);
        let deleteViaAjax = pobjName+'_Clone' + cloneId;
        var pfwfrID = $('.' + deleteViaAjax + '> div > div > div > .'+pobjName+'_sourceSelect' + cloneId).attr('name');
        var source_row_id = $('.' + deleteViaAjax + '> div > div > div > .'+pobjName+'_sourceSelect' + cloneId).val();
        var dest_row_id = $('.' + deleteViaAjax + '> div > div > div > .'+pobjName+'_destSelect' + cloneId).val();
        var editId = $('.' + deleteViaAjax + '> div > div > div > .'+pobjName+'_sourceSelect' + cloneId).attr('data-editId');
        if(editId==undefined || editId=="")
        {
            editId = $('.' + deleteViaAjax + '> div > div > div > .'+pobjName+'_destSelect' + cloneId).attr('data-editId');
        }

        if (!editId) {
            //alert(selectorName);
            $("." + selectorName).remove();
            $('#Mapping'+pobjName+'Count').val(newOldCount - 1);
        }
        else {
            confirmation1("Confirm!","Are you sure you want to delete this mapping?",pobjName,pfwfrID,source_row_id,dest_row_id,editId,csrf_token,selectorName,newOldCount)
        }
    }

}

function confirmation1(title,content,pobjName,pfwfrID,source_row_id,dest_row_id,editId,csrf_token,selectorName,newOldCount){
    $.confirm({
        title: title,
        icon: 'fa fa-question-circle',

        closeAnimation: 'scale',
        closeIcon: true,
        content: content,
        buttons: {
            'confirm': {
                text: 'Confirm',
                btnClass: 'btn-success',
                action: function () {
                    var fixedUrl = $("#AjaxCallUrl").val();
                    $.ajax({
                        type: 'POST',
                        url: fixedUrl + '/deletemapping',
                        data: {
                            pobjName,
                            pfwfrID,
                            source_row_id,
                            dest_row_id,
                            editId,
                            '_token': csrf_token,
                        },
                        success: function (data) {
                            if (data.status_code == 1) {
                                $("." + selectorName).remove();
                                $('#Mapping'+pobjName+'Count').val(newOldCount - 1);
                                toastr.success(data.status_text);
                                //call open mapping function for reload mapping data
                                openMapping(type = 1,isConnectionPage=0)
                            }
                            else
                            {
                                toastr.error(data.status_text);
                            }
                        }
                    });
                }
            },
            cancel: function () {
                //console.log('skip it');
            },
        }
    });
}

function disconnect_confirmation(title,content,userIntegId,platform_id,platform_account_id,csrf_token){
    $.confirm({
        title: title,
        icon: 'fa fa-question-circle',

        closeAnimation: 'scale',
        closeIcon: true,
        content: content,
        buttons: {
            'confirm': {
                text: 'Confirm',
                btnClass: 'btn-success',
                action: function () {
                    var fixedUrl = $("#AjaxCallUrl").val();
                    showOverlay()
                    $.ajax({
                        type: 'POST',
                        url: fixedUrl + '/Disconnect',
                        data: {
                            'userIntegId': userIntegId,
                            'platform_id':platform_id,
                            'platform_account_id':platform_account_id,
                            '_token': csrf_token,
                        },
                        success: function (data) {

                            hideOverlay();
                            if(data.status_code==1){
                                //handle user workflow check status...
                                if (localStorage.hasOwnProperty("workflow_initial_data_sync_status_userIntegId_"+userIntegId)) 
                                {
                                    localStorage.removeItem("workflow_initial_data_sync_status_userIntegId_"+userIntegId);
                                }
                                toastr.success(data.status_text);
                                window.location.reload();
                            }
                        },
                        error: function(){
                            hideOverlay();
                        }
                    });
                }
            },
            cancel: function () {
                //console.log('skip it');
            },
        }
    });
}

function addOtherMap(pobjName)
{
    var oldCount = $('#Mapping'+pobjName+'Count').val();
    $('#Mapping'+pobjName+'Count').val(parseInt(oldCount) + 1);
    const tabId = $('.'+pobjName+' > .col-md-12').first().html();
    //console.log(tabId);
    $('.'+pobjName+'_dynemic > .row').append('<div class="col-md-12 '+pobjName+'_Clone'+oldCount+'" style="display:flex;padding:0">' + tabId + '</div>');
    let newClass = '.'+pobjName+'_Clone' + oldCount;

    $(newClass + ' >  .addMappingField').css('display', 'none');
    $(newClass + ' > .removeMappingField').css('display', 'block');

    let source_input_type = $(newClass).find(".sourceSelect").attr('type'); 
    let dest_input_type = $(newClass).find(".destSelect").attr('type'); 
    if(source_input_type !="select-one")
    {
        $(newClass).find(".sourceSelect").val("");   
    }
    else
    {
        $(newClass).find(".sourceSelect option").removeAttr('selected');
    }

    if(dest_input_type !="select-one")
    {
        $(newClass).find(".destSelect").val("");   
    }
    else
    {
        $(newClass).find(".destSelect option").removeAttr('selected');
    }

    $(newClass).find(".sourceSelect").attr('data-editId','');
    $(newClass).find(".destSelect").attr('data-editId','');
    
}

function removeOtherMap(t,pobjName)
{
    var csrf_token = $('meta[name="csrf-token"]').attr('content');
    var oldCount = $('#Mapping'+pobjName+'Count').val();
    let newOldCount = parseInt(oldCount);
    var selectorName = $(t).parent().closest('div').attr('class').split(' ').pop();
    // console.log(selectorName);

    var pfwfrID = $(t).parent().find('.sourceSelect').attr('name');
    var source_row_id = $(t).parent().find('.sourceSelect').attr('name');
    var dest_row_id = $(t).parent().find('.destSelect').attr('name');
    let editId =  $(t).parent().find('.sourceSelect').attr('data-editId');
    if (!editId) {
        $("." + selectorName).remove();
        $('#Mapping'+pobjName+'Count').val(newOldCount - 1);
    }
    else {
        confirmation1("Confirm!","Are you sure you want to delete this mapping?",pobjName,pfwfrID,source_row_id,dest_row_id,editId,csrf_token,selectorName,newOldCount)
    }
}

function confirmationDelFile(title,content,name,platform,editid,userIntegId,file,type,isConnectionPage){
    $.confirm({
        title: title,
        icon: 'fa fa-question-circle',

        closeAnimation: 'scale',
        closeIcon: true,
        content: content,
        buttons: {
            'confirm': {
                text: 'Confirm',
                btnClass: 'btn-success',
                action: function () {
                    var fixedUrl = $("#AjaxCallUrl").val();
                    $.ajax({
                        type: 'POST',
                        url: fixedUrl + '/delete_mapping_file',
                        data: {'_token' : $('meta[name="csrf-token"]').attr('content'),name,platform,editid,userIntegId},
                        success: function (data) {
                            if (data.status_code == 1) {
                                toastr.success(data.status_text);
                                file.previewElement.remove();
                                openMapping(type,isConnectionPage);
                            }
                            else
                            {
                                toastr.error(data.status_text);
                            }
                        }
                    });
                }
            },
            cancel: function () {
                openMapping(type,isConnectionPage);
            },
        }
    });
}


 $(document).on('change', '.full_inv_syn', function(){
    let value = $(".full_inv_syn").val();
    if(value=="Twice")
    {
        makeTimePicker("12:00");
    }
    if(value=="Once")
    {
        makeTimePicker("23:00");    
    }
 });

 function makeTimePicker(max)
 {
        $(".pickatime").flatpickr({ 
        enableTime: true,
        noCalendar: true,
        dateFormat: "H:i",
        time_24hr: true,
        minTime: "01:00",
        maxTime: max
    });
 }

 function loadDepDropData(t,side)
 {
    let selectedSide = (side==1) ?"source_depDrop" : "dest_depDrop";

    let csrf_token = $('meta[name="csrf-token"]').attr('content');
    let fixedUrl = $("#AjaxCallUrl").val();
    let userIntegId = $("#input_IntegPlateformId").val();

    let pltObjId = $(t).attr('data-pltobjid');
    let wfrid = $(t).attr('data-wfrid');
    let pltId = $(t).attr('data-pltId');
    let zone = $(t).val();
    let loadObj = $(t).attr('data-loadObj');
    if(zone)
    {
        showOverlay()
        $.ajax({
            type: 'POST',
            url: fixedUrl + '/load_dep_drop_down_data',
            data: {
                'pltId':pltId,
                'zone':zone,
                'pltObjId':pltObjId,
                'userIntegId': userIntegId,
                '_token': csrf_token,
                'loadObj':loadObj,
                'wfrid':wfrid
            },
            success: function (data) {
                hideOverlay();
                // if(data.status_code==1){
                //     toastr.success(data.status_text);
                // }
                let optionVal='<option value="">Select Shipping Method</option>';
                if((data.mapObjData) && (data.mapObjData.length > 0))
                {
                    $.each(data.mapObjData, function (key,value) {
                        optionVal +=`<option value="${value.optionId}">${value.optionValue}</option>`;
                    });
                    $(t).parent().parent().find('.'+selectedSide).html(optionVal);
                }
                else
                {
                    $(t).parent().parent().find('.'+selectedSide).html(optionVal);
                }
            },
            error: function(){
                hideOverlay();
            }
        });
    }
    else
    {
        let optionVal='<option value="">Select Shipping Method</option>';
        $(t).parent().parent().find('.'+selectedSide).html(optionVal);
    }
        
        
 }

 function flow_oNoFF_confirmation(title,content,userIntegId,pfwfrID,status,fixedUrl,csrf_token){
    
    $.confirm({
        title: title,
        icon: 'fa fa-question-circle',

        closeAnimation: 'scale',
        closeIcon: true,
        content: content,
        buttons: {
            'confirm': {
                text: 'Confirm',
                btnClass: 'btn-success',
                action: function () {
                    $.ajax({
                        method: 'post',
                        url: fixedUrl + '/updateIntegrationFlow',
                        dataType: "json",
                        data: {
                            'userIntegId': userIntegId,
                            'pfwfrID': pfwfrID,
                            'status': status,
                            '_token': csrf_token,
                        },
                        success: function (data) {
                            if(data.status_code==0){
                                toastr.error(data.status_text);
                                $('#connections-tab').trigger('click');
                                $('#customSwitch' + pfwfrID).prop('checked', false);
                            }
                            getFlowData();
                            getUserWorkflowStatus();
                            //window.location.reload();
                        }
                    });
                }
            },
            cancel: function () {
                if(status == 1){
                    $('#customSwitch' + pfwfrID).prop('checked', false);
                }else{
                    $('#customSwitch' + pfwfrID).prop('checked', true);
                }
            },
        }
    });
}

//new added for these need to add new version
function getExtensiveIdentityMappingData(validErrStatus,pobjName)
{
    const AvailIdentSide = JSON.parse($('#Mapping_'+pobjName+'_Sides').val());

    let mapping_type="regular";

    if(AvailIdentSide.length==1)
    {
        mapping_type = "default";
    } 
    //if source side available
    let unqIdentSource=null; let unqIdentDest=null; let platform_object_id="";
    if(AvailIdentSide.includes("source"))
    {
        platform_object_id = $('#'+pobjName+'_unqIdentSource').attr("alt");
        unqIdentSource = $('#'+pobjName+'_unqIdentSource').val();
        if (unqIdentSource == "") {
            $('.'+pobjName+'_unqIdentSource').css({ "border-color": "red", "border-width": "1px", "border-style": "solid" });
            validErrStatus=1;
        }
        else {
            $('.'+pobjName+'_unqIdentSource').css({ "border-color": "#D8D6DE", "border-width": "1px", "border-style": "solid" });
        }
    }
    //if destination side available
    if(AvailIdentSide.includes("destination"))
    {   
        platform_object_id = $('#'+pobjName+'_unqIdentDest').attr("alt");
        unqIdentDest = $('#'+pobjName+'_unqIdentDest').val();
        if (unqIdentDest == "") {
            $('.'+pobjName+'_unqIdentDest').css({ "border-color": "red", "border-width": "1px", "border-style": "solid" });
            validErrStatus=1;
        }
        else {
            $('.'+pobjName+'_unqIdentDest').css({ "border-color": "#D8D6DE", "border-width": "1px", "border-style": "solid" });
        }
    }

    response_data = `{"data_map_type":"field","platform_object_id":${platform_object_id},"mapping_type":"${mapping_type}","source_row_id":${unqIdentSource},"destination_row_id":${unqIdentDest},"status":1,"extensive_identity_obj_name":"${pobjName}"},`;

    return response_data;

}

