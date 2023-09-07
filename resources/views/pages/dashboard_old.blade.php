@extends('layouts.master')

@section('head-content')

<style>

</style>

@endsection

@section('title', 'Dashboard')

@section('side-bar')
@include('layouts.menu-bar')
@endsection

@section('breadcrumb')
  <li class="breadcrumb-item active">Dashboard</li>
@endsection

@push('page-style')
	<style>
		.accoBtn{
			width: 25px;
			height: 25px;
			color: #6e6b7b;
			cursor:pointer;
			transition: transform .5s;
		}
		.setup-icon-box {
			padding-right:10px; 
		}
		.dhide{
			visibility: hidden;
		}
	</style>
@endpush

@section('page-content')
      <!-- Workflow Section -->
			<section id="dashboard-ecommerce">
			
			  <div class="row match-height">
			    
				<!-- workflow Card -->
				<div class="col-xl-12 col-md-12 col-12">
				  <div class="card card-statistics">
					<div class="card-header card-header-style1">
						<div class="step-count setup-icon-box">
						   <span><img src="{{asset('public/esb_asset/assets/images/svg/app-2.svg')}}" width="30" height="30" class="svg-purple"></span>
						</div>
						<div style="flex: auto;" class="work_flow_title_wrapper ">
						   <span><h4 class="card-title"> Make Workflow</h4></span>
						</div>
						
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-chevron-down accoBtn"><polyline points="6 9 12 15 18 9"></polyline></svg>
						
					
					  </div>
					<div class="card-body statistics-body ">
					  <div class="row justify-content-center pt-2">
						<div class="col-xl-12 col-sm-12 col-12">
						   <h1 class="text-center text-primary">Create your own workflow</h1>
						   <p class="text-center">Know exactly what you want to build? Select the apps you want to connect to start your custom setup.</p>
						</div>
						
					 </div>
					 <div class="row justify-content-center py-1 wf_step1 ">
						<div class="col-xl-4 col-sm-5 col-md-5 col-12 mb-2 mb-xl-0">
							<input type="hidden" class="workflow_id" value=""/>
						    <div class="select-item mb-1">
							  <label class="my-label">Connect this app...</label>
							  <select data-placeholder="Select an app..." class="from-platform form-control platformf" id="select2-icons">
								  <option value=""  data-icon="{{asset('public/esb_asset/assets/images/search.png')}}" selected disabled>Select an app... </option>
                  {!!$platforms['options']!!}
							  </select>
							  <span class="field_error">Field value is required</span>
							</div>
							
						</div>
						<div class="col-xl-auto col-sm-auto col-12 mb-2 mb-xl-0 p-0 d-none d-sm-block">
						   <img src="{{asset('public/esb_asset/assets/images/svg/connection-2.svg')}}" class="connection-icon cont1 " style="">  
						</div>
						<div class="col-xl-4 col-sm-5 col-md-5 col-12 mb-2 mb-xl-0">
						  
						  
						   <!-- Icons -->
							<div class="select-item mb-1">
							  <label class='my-label'>with this one</label>
							  <select data-placeholder="Select an app..." class="to-platform form-control platformf" id="select2-icons2">
							      <option value="" data-icon="{{asset('public/esb_asset/assets/images/search.png')}}"  selected disabled>Select an app... </option>
                    {!!$platforms['options']!!}
							  </select>
							  <span class="field_error">Field value is required</span>
							</div>
			
						</div>
					 </div>
						
					<div class="wf_step2">
						<div class="row justify-content-center pb-1">

							<div class="col-xl-4 col-sm-5 col-md-5  col-12 mb-2 mb-xl-0">
							
								<div class="select-item mb-1">
								  <label class="my-label">Select a Trigger</label>
								  <select data-placeholder="Select a Trigger" class="select2-icons from-event form-control wf-select" id="select2-icons3">
									  
									 
								  </select>
								  <span class="field_error">Field value is required</span>
								</div>
								
							</div>
							<div class="col-xl-auto col-sm-auto col-12 mb-2 mb-xl-0 p-0 d-none d-sm-block">
							   <img src="{{asset('public/esb_asset/assets/images/svg/arrow.svg')}}" class="connection-icon cont2" style="width: 32px;padding: 0 5px;">  
							</div>
							<div class="col-xl-4 col-sm-5 col-md-5 col-12 mb-2 mb-xl-0">
							  
							  
							   <!-- Icons -->
								<div class="select-item mb-1">
								  <label class='my-label'>Select an Action</label>
								  <select data-placeholder="Select an Action" class="select2-icons to-action form-control wf-select" id="select2-icons4">
									  
								  </select>
								  <span class="field_error">Field value is required</span>
								</div>
				
							</div>
						</div>
												
						<div class="row justify-content-center pb-1">
							<div class="col-xl-4 col-sm-5 col-md-5  col-12 mb-2 mb-xl-0">
								<input type="hidden" id='from-connected' value="0"/>
								{{-- <button type="button" class="btn btn-warning btn-block waves-effect waves-float waves-light api-connect-btn to-btn">Connect</button> --}}
								<div class="select-item mb-1">
								  <label class='my-label from-ac-label'>Select Account</label>
								  <select data-placeholder="Select an Account" class="select2-icons from-account-select form-control wf-select" id="from-account-select">
									  
								  </select>
								  <span class="field_error">Field value is required</span>
								</div>
								
							</div>
							<div class="col-xl-auto col-sm-auto col-12 mb-2 mb-xl-0 p-0 d-none d-sm-block dhide">
								<img src="{{asset('public/esb_asset/assets/images/svg/arrow.svg')}}" class="" style="width: 32px;padding: 0 5px;">  
							 </div>
							 <div class="col-xl-4 col-sm-5 col-md-5  col-12 mb-2 mb-xl-0">
								<input type="hidden" id='to-connected' value="0"/>
								{{-- <button type="button" class="btn btn-warning btn-block waves-effect waves-float waves-light api-connect-btn from-btn">Connect</button> --}}
								<div class="select-item mb-1">
								  <label class='my-label to-ac-label'>Select Account</label>
								  <select data-placeholder="Select an Account" class="select2-icons to-account-select form-control wf-select" id="to-account-select">
									  
								  </select>
								  <span class="field_error">Field value is required</span>
								</div>
							</div>
							<div class="col-xl-12 col-sm-12 col-12 my-2 mb-xl-0 text-center">
								 <button type="button" class="btn btn-success waves-effect waves-float waves-light connect-now" data-text='Connect Now' data-clicked='Connecting . . '>
									<span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'>Connect Now</span>
								 </button>
							</div>
							
						  </div>
					  </div>
					  
					</div>
				  </div>
				</div>
				<!--/ Statistics Card -->
				<!-- workflow Card -->
				<div class="col-xl-12 col-md-12 col-12">
					<div class="card card-statistics">
					  <div class="card-header card-header-style1">
						  <div class="step-count setup-icon-box">
							 <span><img src="{{asset('public/esb_asset/assets/images/svg/app-2.svg')}}" width="30" height="30" class="svg-purple"></span>
						  </div>
						  <div style="flex: auto;" class="work_flow_title_wrapper ">
							 <span><h4 class="card-title"> Field Mapping</h4></span>
						  </div>
						  
						  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-chevron-down accoBtn"><polyline points="6 9 12 15 18 9"></polyline></svg>
						  
					  
						</div>
					  <div class="card-body statistics-body ">
						<div class="row justify-content-center pt-2">
						  <div class="col-xl-12 col-sm-12 col-12">
							 <h1 class="text-center text-primary">Configure field mapping</h1>
							 {{-- <p class="text-center">Know exactly what you want to build? Select the apps you want to connect to start your custom setup.</p> --}}
						  </div>
						  	<div class="col-xl-12 col-sm-12 col-12 my-2 mb-xl-0 text-center">
								<button type="button" class="btn btn-primary waves-effect waves-float waves-light refresh-fields" data-text="Connect Now" data-clicked="Connecting . . ">
									<i data-feather="refresh-cw"></i> Refresh Fields
								</button>
								<button type="button" class="btn btn-success waves-effect waves-float waves-light save-fields" data-text="Connect Now" data-clicked="Connecting . . ">
									<i data-feather="save"></i> Save Mapping
								</button>
							</div>
					   </div>
					   <div class="row justify-content-center py-1 mapping-area"></div>
						
					  </div>
					</div>
				  </div>
				  <!--/ Statistics Card -->
			  </div>

			 
			 </section>
			<!-- Dashboard Ecommerce ends -->
			
			
			<!-- Workflow Section -->
      @if(count($workflow))
			<section id="dashboard-ecommerce">
        <div class="col-md-12 my-1">
          <div class="group-area">
            <h2>Recommended workflows for you.</h2>
          </div>
          </div>
        
          <div class="row match-height">
            
          <!-- workflow Card -->
          <div class="col-xl-12 col-md-12 col-12">
          
            @foreach($workflow as $wk => $wv)
              <div class="card work-item-card">
                <div class="card-body work-item">
                  <div class="row">
                  <div class="col-xl-12 col-sm-12 col-12 mb-2 mb-xl-0">
                    <div class="media">
                    <div class="avatar bg-light-primary mr-2">
                      <div class="avatar-content">
                      <img src="{{asset($wv->p1_image)}}" class="avatar-icon">
                      </div>
                      <div class="avatar-content">
                      <img src="{{asset($wv->p2_image)}}" class="avatar-icon">
                      </div>
                    </div>
                    
                    <div class="media-body my-auto">
                      <h4 class="font-weight-bolder mb-0 dark-text">Sync {{$wv->p1_name}} to {{$wv->p2_name}}</h4>
                      <p class="card-text font-small-3 mb-0">{{$wv->p1_name}} + {{$wv->p2_name}}</p>
                    </div>
                    
                    <div class="action">
                      <button type="button" data-id="{{$wv->workflow_id}}" class="btn btn-primary waves-effect waves-float waves-light"> Try it</button>
                    </div>
                    </div>
                  </div>
                  </div>
                </div>
              </div>
            @endforeach

          </div>
          <!--/ Statistics Card -->
          </div>
			 </section>
       @endif
			<!-- Dashboard Ecommerce ends -->
			
@endsection

@push('page-script')
<script>
	var pop;
	var ac_cls_type = '';
	CreateInterval();
	 //select2 with icons
  
	 ! function (e, t, s) {
		"use strict";
		var i = s(".select2");
		//var url = window.location.origin+"BSE/assets/images/";
		
		// r.each((function () {
		// 	var e = s(this);
		// 	e.wrap('<div class="position-relative select-with-img"></div>'), e.select2({
		// 		dropdownAutoWidth: !0,
		// 		width: "100%",
		// 		minimumResultsForSearch: 0 / 0,
		// 		dropdownParent: e.parent(),
		// 		templateResult: u,
		// 		templateSelection: u,
		// 		placeholder: "Search for a repository",
		// 		escapeMarkup: function (e) {
		// 			return e
		// 		},
		// 		minimumInputLength: 0
		// 	})
		// }));

		
		$(".to-platform,.from-platform").each(function(){
			MakeSelectPlatform($(this));
		})

		
		//workflow select 
		const wfs =jQuery(".platformf");
		
		wfs.change(function(){
			
			const e = jQuery(this);
			const wf_step2 = jQuery('.wf_step2');
			const v = e.val();
			
			let icon = e.find(':selected').data('icon');
			let name = e.find(':selected').data('name');
			let url = e.find(':selected').data('url');

			if(e.hasClass("from-platform")){
				$('.from-btn').html('<img width="20" src="'+icon+'" /> Connect '+name);
				$('.from-btn').data('data-src',url);
			}else if(e.hasClass("to-platform")){
				$('.to-btn').html('<img width="20" src="'+icon+'" /> Connect '+name);
				$('.to-btn').data('data-src',url);
			}
			
			const wf_f1 =jQuery(".from-platform").val();
			const wf_f2 =jQuery(".to-platform").val();
			// const wf_f3 =jQuery("#select2-icons3").val();
			// const wf_f4 =jQuery("#select2-icons4").val();
			
			console.log(wf_f1);
			console.log(wf_f2);
			
			if(wf_f1 !=null && wf_f2 !=null){
				 $.ajax({
                      type: 'POST',
                      url: "{{url('/getPlatformEventsAndAction')}}",
                      data: {
                          '_token': $('meta[name="csrf-token"]').attr('content'),'from_platform':wf_f1,
						  'to_platform':wf_f2
                      },
                      beforeSend: function() {
                          showOverlay();
                      },
                      success: function(response) {
                          hideOverlay();
                          if (response.status_code === 1) {
							$('.from-event').html(response.event_option);
							MakeSelectPlatform($('.from-event'));
							$('.to-action').html(response.action_option);
							MakeSelectPlatform($('.to-action'));
							$('.to-account-select').html(response.to_acc_opts);
							$('.from-account-select').html(response.from_acc_opts);
							MakeSelectPlatform($('.to-account-select'));
							MakeSelectPlatform($('.from-account-select'));
							  wf_step2.slideDown();
                          } else {
                            toastr.error(response.status_text);
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
				
				jQuery(".cont1").parent().addClass('valid');
			}else{
				wf_step2.slideUp();
				jQuery(".cont1").parent().removeClass('valid');
			}
			// if(wf_f1 !=null && wf_f2 !=null && wf_f3 !=null && wf_f4 !=null ){
			// 	jQuery(".cont2").parent().addClass('valid');
				
			// }else{
			// 	jQuery(".cont2").parent().removeClass('valid');
			// }
			
		});
		
		
		
	}(window, document, jQuery);

	function formatRes(e) {
		e.element;
		//return e.id ? feather.icons[s(e.element).data("icon")].toSvg() + e.text : e.text
		//console.log(e.text + ' ', s(e.element).data('icon'));
		
		if( $(e.element).data('icon')==undefined){
			return "<span class='text'>" + e.text + "</span>";
		}else{
			return "<img src='"+ $(e.element).data('icon')+"'><span class='text'>" + e.text + "</span>";
		}
		
		//return "<img src=''>" + e.text ;
	}

	function MakeSelectPlatform(e){
			e.wrap('<div class="position-relative select-with-img"></div>'), 
			e.select2({
				dropdownAutoWidth: !0,
				width: "100%",
				minimumResultsForSearch: 0 / 0,
				dropdownParent: e.parent(),
				templateResult: formatRes,
				templateSelection: formatRes,
				placeholder: "Search for a repository",
				escapeMarkup: function (e) {
					return e
				},
				minimumInputLength: 0
			})
		}
	
	$(document.body).on('click','.connect-now',function(){
		let fe = $('.from-event').val();
		let te = $('.to-action').val();
		let fp = $(".from-platform").val();
		let tp = $(".to-platform").val();
		let fas = $(".from-account-select").val();
		let tas = $(".to-account-select").val();
		

		$('.field_error').hide();
		let isValid = true;
		if(!fe){
			$('.from-event').closest('.select-item').find('.field_error').show();
			isValid = false;
		}
		if(!te){
			$('.to-action').closest('.select-item').find('.field_error').show();
			isValid = false;
		}
		if(!fp){
			$('.from-platform').closest('.select-item').find('.field_error').show();
			isValid = false;
		}
		if(!tp){
			$('.to-platform').closest('.select-item').find('.field_error').show();
			isValid = false;
		}
		if(!fas || fas=='add-new'){
			$('.from-account-select').closest('.select-item').find('.field_error').show();
			isValid = false;
		}
		if(!tas || tas=='add-new'){
			$('.to-account-select').closest('.select-item').find('.field_error').show();
			isValid = false;
		}
		if(!isValid)
			return false;
		 $.ajax({
			type: 'POST',
			url: "{{url('/connectWorkflow')}}",
			data: {
				'_token': $('meta[name="csrf-token"]').attr('content'),'from_platform':fp,
				'to_platform':tp,'from_event':fe,'to_event':te
			},
			beforeSend: function() {
				showOverlay();
			},
			success: function(response) {
				hideOverlay();
				if (response.status_code === 1) {
					// window.location.href = response.redirect_url;
					$('.workflow_id').val(response.id);
					if($('.accoBtn:eq(0)').closest('.card').find('.card-body').is(':visible')){
						$('.accoBtn:eq(0)').trigger('click');
					}
					if(!$('.accoBtn:eq(1)').closest('.card').find('.card-body').is(':visible')){ // Open 2nd box
						$('.accoBtn:eq(1)').trigger('click');
					}
					getMappingFields(response.id);
				} else {
					toastr.error(response.status_text);
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
	})

	function getMappingFields(id){
		$.ajax({
			type: 'POST',
			url: "{{url('/GetMappingFields')}}",
			data: {
				'_token': $('meta[name="csrf-token"]').attr('content'),'workflow_id':id
			},
			beforeSend: function() {
				showOverlay();
			},
			success: function(response) {
				$('.mapping-area').html('');
				hideOverlay();
				let k =0;
				if (response.status_code === 1) {
					$('.mapping-area').html(response.data);
					$('.destination_field_id').each(function(k){ // For existing product selection process & initialization
						index = ++k;
						// bpfid_selected = $(this).closest('tr').find('.bpfid_selected').val();
						$('#destination_field_id'+index).select2({
							placeholder: "--Select Field--",
							allowClear: true
						});

						// $(this).closest('tr').find('#bp_field_map'+index).val(bpfid_selected).trigger('change');
					})
				} else {
					toastr.error(response.status_text);
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

	$(document).on('click','.api-connect-btn',function(){
		var url = $(this).data('src');
		if(url){
			AuthAPI(url);
		}
	})

	$(document).on('change','.to-account-select,.from-account-select',function(){
		var val = $(this).val();
		var url = $(this).find('option:selected').data('src');
		if($(this).hasClass('to-account-select')){
			ac_cls_type = 'to-account-select';
		}else if($(this).hasClass('from-account-select')){
			ac_cls_type = 'from-account-select';
		}
		
		if(val=='add-new' && url){
			$(this).val('');
			AuthAPI(url);
		}
	})

	// Field refresh
	$(document).on('click','.refresh-fields',function(){
		var val = $('.workflow_id').val();
		if(val){
			getMappingFields(val);
		}else{
			toastr.error('Please save the workflow first');
		}
	})

	$(document).on('click','.save-fields',function(){

		valid_mapping = true;
        err_msg = '';
        mapping_arr = [];
        mapping_bp_arr = [];
        $('.product_fields_mapping_table tr').each(function(){
            rowobj = {'lsfid':'','bpfid':'','rowtype':'product','id':''};
            rowobj.lsfid = $(this).find('.lsfid').val();
            rowobj.bpfid = $(this).find('.bp_field_map').val();
            join_str = rowobj.lsfid+'='+rowobj.bpfid;
            if(rowobj.lsfid && rowobj.bpfid && mapping_arr.indexOf(join_str) > -1){
                err_msg = 'Product mapping already exists';
                valid_mapping = false;
                return false;
            }
            if( rowobj.bpfid && mapping_bp_arr.indexOf(rowobj.bpfid) > -1){
                err_msg = 'Same Brightpearl field cannot be mapped twice';
                valid_mapping = false;
                return false;
            }
            mapping_arr.push(join_str);
            mapping_bp_arr.push(rowobj.bpfid);
            data_post.mappings.push(rowobj);
        })
        if(!valid_mapping){
            toastr.error(err_msg);
            return false;
        }
	})

	function AuthAPI(url){
		pop = null;
		clearInterval(checkConnect);
		CreateInterval();
		//baseUrl+'/LSOauthHandler'
		pop = window.open(url,'popup','width=600,height=600,scrollbars=no,resizable=no');
	}

	function CreateInterval(){
		let elemThis = this;
		checkConnect = setInterval(function() {
			if (!pop || !pop.closed) return;
			clearInterval(checkConnect);
			// window.location.reload();
			var platform_id = '';
			if(ac_cls_type=='to-account-select'){
				platform_id = $('.to-platform').val();
			}else if(ac_cls_type=='from-account-select'){
				platform_id = $('.from-platform').val();
			}
			$.ajax({
			    type: 'POST',
			    url: "{{url('/getConnectedAccounts')}}",
			    data: {
			        '_token': $('meta[name="csrf-token"]').attr('content'),platform_id:platform_id
			    },
			    beforeSend: function() {
			        showOverlay();
			    },
			    success: function(response) {
			        hideOverlay();
			        if (response.status_code === 1) {
			        $options = response.acc_opts;
						$('.'+ac_cls_type).html($options);
						MakeSelectPlatform('.'+ac_cls_type);
			        } else {
			        	toastr.error(response.status_text);
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
		}, 100);
	}

	jQuery(document).on('click','.accoBtn', function(){

	jQuery(this).parents('.card').find('.card-body').slideToggle();
	jQuery(this).parents('.card').toggleClass("s-close");
	//alert("ok");

	});

	
	//select2 with icons end
  
</script>
@endpush
