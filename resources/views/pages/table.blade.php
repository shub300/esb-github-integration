@extends('layouts.master')

@section('head-content')
 <!-- BEGIN: Page Vendor JS-->
 
 
    <link rel="stylesheet" type="text/css" href="https://esb.apiworx.net/integration/public/esb_asset/vendors/css/tables/datatable/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" type="text/css" href="https://esb.apiworx.net/integration/public/esb_asset/vendors/css/tables/datatable/responsive.bootstrap4.min.css">
    <link rel="stylesheet" type="text/css" href="https://esb.apiworx.net/integration/public/esb_asset/vendors/css/pickers/flatpickr/flatpickr.min.css">
	
	

    <!-- END: Page Vendor JS-->
<style>

</style>

@endsection
  
@section('title', 'Table')

@section('side-bar')    
@include('layouts.mapping-sidebar')
@include('layouts.menu-bar')
@endsection

@section('breadcrumb')
  <li class="breadcrumb-item active">Table</li>
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
		
		.dataTables_length select.form-select{
			border: 1px solid #ccc;
			padding: 5px !important;
			border-radius: 4px;
		}
		
		.dataTables_info{margin-bottom: 10px;}
		
		.dt-action-buttons.text-right,
		.dataTables_wrapper .card-header, .dataTables_wrapper .dt-button.add-new {display:none;}
		
		
		table.dataTable>thead .sorting:before, table.dataTable>thead .sorting_asc:before, table.dataTable>thead .sorting_desc:before, table.dataTable>thead .sorting_asc_disabled:before, table.dataTable>thead .sorting_desc_disabled:before {
           right: .5em !important;
		   content:"";
		}
		table.dataTable>thead .sorting:after, table.dataTable>thead .sorting_asc:after, table.dataTable>thead .sorting_desc:after, table.dataTable>thead .sorting_asc_disabled:after, table.dataTable>thead .sorting_desc_disabled:after{
			content:"";
		}
		
		
		
		.labelStyle1{
			color: #b3b3b3;
			padding: 8px;
			border: 1px solid;
			border-radius: 4px;
		}
	
	.feather, [data-feather] {
		height: 1.5rem;
		width: 1.5rem;
	}
	
	.b-1{border:1px solid #7367F0;}
	.removeBtn{cursor:pointer;color:#ff0000;}
	
	.addBtn{cursor:pointer;}
	.DItem .addBtn,.D_MainItem .removeBtn{display:none;}
	
	.mappingFieldWrapper{
		    margin-left: -3px;
            margin-right: -3px;
	}
	.mappingFieldWrapper>div{
		padding-left: 3px;
        padding-right: 3px;
	}
	
	#mapping-area{
		overflow-y: auto;
		height: calc(100vh - 100px);
		overflow-x: hidden;
	}
	
	.accountBox .accImg{
		width:80px;
		height:80px;
		border-radius:50%
	}
		
	</style>
@endpush

@section('page-content')
       
	   
        <div class="content-body">

<section id="basic-tabs-components">
  <div class="row match-height">
    <!-- Basic Tabs starts -->
    <div class="col-xl-12 col-lg-12">
      
          <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item">
              <a class="nav-link active" id="home-tab" data-bs-toggle="tab" href="#home" aria-controls="home" role="tab" aria-selected="true" >Home</a>
            </li>
            <li class="nav-item">
              <a class="nav-link" id="profile-tab" data-bs-toggle="tab" href="#profile" aria-controls="profile" role="tab" aria-selected="false" >Service</a>
            </li>
            <!--<li class="nav-item">
              <a href="disabled" id="disabled-tab" class="nav-link disabled">Disabled</a>
            </li>-->
            <li class="nav-item">
              <a class="nav-link" id="about-tab" data-bs-toggle="tab" href="#about" aria-controls="about" role="tab" aria-selected="false" >Account</a>
            </li>
          </ul>
          <div class="tab-content">
            <div class="tab-pane active" id="home" aria-labelledby="home-tab" role="tabpanel">
              
			  
			        <!-- Basic table -->
					<section id="basic-datatable">
					  <div class="row">
						<div class="col-12">
						  <div class="card">
							<table class="datatables-basic table">
							  <thead>
								<tr>
								  <th></th>
								  <th></th>
								  <th>id</th>
								  <th>Name</th>
								  <th>Email</th>
								  <th>Date</th>
								  <th>Salary</th>
								  <th>Status</th>
								  <th>Action</th>
								</tr>
							  </thead>
							</table>
						  </div>
						</div>
					  </div>
					 
					  <div class="modal modal-slide-in fade" id="modals-slide-in">
						<div class="modal-dialog sidebar-sm">
						  <form class="add-new-record modal-content pt-0">
							<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close">×</button>
							<div class="modal-header mb-1">
							  <h5 class="modal-title" id="exampleModalLabel">New Record</h5>
							</div>
							<div class="modal-body flex-grow-1">
							  <div class="mb-1">
								<label class="form-label" for="basic-icon-default-fullname">Full Name</label>
								<input
								  type="text"
								  class="form-control dt-full-name"
								  id="basic-icon-default-fullname"
								  placeholder="John Doe"
								  aria-label="John Doe"
								/>
							  </div>
							  <div class="mb-1">
								<label class="form-label" for="basic-icon-default-post">Post</label>
								<input
								  type="text"
								  id="basic-icon-default-post"
								  class="form-control dt-post"
								  placeholder="Web Developer"
								  aria-label="Web Developer"
								/>
							  </div>
							  <div class="mb-1">
								<label class="form-label" for="basic-icon-default-email">Email</label>
								<input
								  type="text"
								  id="basic-icon-default-email"
								  class="form-control dt-email"
								  placeholder="john.doe@example.com"
								  aria-label="john.doe@example.com"
								/>
								<small class="form-text"> You can use letters, numbers & periods </small>
							  </div>
							  <div class="mb-1">
								<label class="form-label" for="basic-icon-default-date">Joining Date</label>
								<input
								  type="text"
								  class="form-control dt-date"
								  id="basic-icon-default-date"
								  placeholder="MM/DD/YYYY"
								  aria-label="MM/DD/YYYY"
								/>
							  </div>
							  <div class="mb-4">
								<label class="form-label" for="basic-icon-default-salary">Salary</label>
								<input
								  type="text"
								  id="basic-icon-default-salary"
								  class="form-control dt-salary"
								  placeholder="$12000"
								  aria-label="$12000"
								/>
							  </div>
							  <button type="button" class="btn btn-primary data-submit me-1">Submit</button>
							  <button type="reset" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
							</div>
						  </form>
						</div>
					  </div>
					</section>
			  
			  
			  
            </div>
            <div class="tab-pane" id="profile" aria-labelledby="profile-tab" role="tabpanel">
             
			 
			 
			 
			        <!-- Basic table -->
					<section id="basic-datatable">
					  <div class="row">
						<div class="col-12">
						  <div class="card">
							<table class="datatables-basic table">
							  <thead>
								<tr>
								  <th></th>
								  <th></th>
								  <th>id</th>  
								  <th>Name</th>
								  <th>Email</th>
								  <th>Date</th>
								  <th>Salary</th>
								  <th>Status</th>
								  <th>Action</th>
								</tr>
							  </thead>
							</table>
						  </div>
						</div>
					  </div>
					 
					  <div class="modal modal-slide-in fade" id="modals-slide-in">
						<div class="modal-dialog sidebar-sm">
						  <form class="add-new-record modal-content pt-0">
							<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close">×</button>
							<div class="modal-header mb-1">
							  <h5 class="modal-title" id="exampleModalLabel">New Record</h5>
							</div>
							<div class="modal-body flex-grow-1">
							  <div class="mb-1">
								<label class="form-label" for="basic-icon-default-fullname">Full Name</label>
								<input
								  type="text"
								  class="form-control dt-full-name"
								  id="basic-icon-default-fullname"
								  placeholder="John Doe"
								  aria-label="John Doe"
								/>
							  </div>
							  <div class="mb-1">
								<label class="form-label" for="basic-icon-default-post">Post</label>
								<input
								  type="text"
								  id="basic-icon-default-post"
								  class="form-control dt-post"
								  placeholder="Web Developer"
								  aria-label="Web Developer"
								/>
							  </div>
							  <div class="mb-1">
								<label class="form-label" for="basic-icon-default-email">Email</label>
								<input
								  type="text"
								  id="basic-icon-default-email"
								  class="form-control dt-email"
								  placeholder="john.doe@example.com"
								  aria-label="john.doe@example.com"
								/>
								<small class="form-text"> You can use letters, numbers & periods </small>
							  </div>
							  <div class="mb-1">
								<label class="form-label" for="basic-icon-default-date">Joining Date</label>
								<input
								  type="text"
								  class="form-control dt-date"
								  id="basic-icon-default-date"
								  placeholder="MM/DD/YYYY"
								  aria-label="MM/DD/YYYY"
								/>
							  </div>
							  <div class="mb-4">
								<label class="form-label" for="basic-icon-default-salary">Salary</label>
								<input
								  type="text"
								  id="basic-icon-default-salary"
								  class="form-control dt-salary"
								  placeholder="$12000"
								  aria-label="$12000"
								/>
							  </div>
							  <button type="button" class="btn btn-primary data-submit me-1">Submit</button>
							  <button type="reset" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
							</div>
						  </form>
						</div>
					  </div>
					</section>
			 
			 
			 
			 
			 
            </div>
            <!--<div class="tab-pane" id="disabled" aria-labelledby="disabled-tab" role="tabpanel">
              <p>
                Chocolate croissant cupcake croissant jelly donut. Cheesecake toffee apple pie chocolate bar biscuit
                tart croissant. Lemon drops danish cookie. Oat cake macaroon icing tart lollipop cookie sweet bear claw.
              </p>
            </div>-->
            <div class="tab-pane" id="about" aria-labelledby="about-tab" role="tabpanel">
              <!-- list section start -->
			  
			  <!--<table class="user-list-table table">
					<thead class="table-light">
					  <tr>
						<th></th>
						<th>User</th>
						<th>Email</th>
						<th>Role</th>
						<th>Plan</th>
						<th>Status</th>
						<th>Actions</th>
					  </tr>
					</thead>
				  </table>-->
						  
			  <div class="row">
			  
			  
				<div class="col-sm-6">
				  <div class="card user-card accountBox">
					<div class="card-body">
					  <div class="row">
						<div class="col-xl-12 col-lg-12 d-flex justify-content-between border-container-lg">
						  <div class="user-avatar-section col-sm-9 col-12">
							<div class="d-flex justify-content-start align-items-center">
							  <img class="img-fluid accImg" src="{{asset('public/esb_asset/images/portrait/small/avatar-s-11.jpg')}}" alt="User avatar">
							  <div class="d-flex flex-column mx-1">
								<div class="user-info">
								  <h4 class="mb-0">Eleanor Aguilar</h4>
								  <span class="card-text">eleanor.aguilar@gmail.com</span>
								</div>
							  </div>
							</div>
						  </div>
						  <div class="d-flex justify-content-start align-items-center col-sm-3 col-12">
							<div class="d-flex">
								<a href="./app-user-edit.html" class="btn btn-primary btn-sm waves-effect waves-float waves-light">Edit</a> &nbsp; 
								<button class="btn btn-outline-danger ms-1 waves-effect btn-sm">Delete</button>
							</div>
					      </div>
						</div>
					  </div>
					</div>
				  </div>
				</div>
				
				<div class="col-sm-6">
				  <div class="card user-card accountBox">
					<div class="card-body">
					  <div class="row">
						<div class="col-xl-12 col-lg-12 d-flex justify-content-between border-container-lg">
						  <div class="user-avatar-section">
							<div class="d-flex justify-content-start align-items-center">
							  <img class="img-fluid accImg" src="{{asset('public/esb_asset/images/portrait/small/avatar-s-11.jpg')}}" alt="User avatar">
							  <div class="d-flex flex-column mx-1">
								<div class="user-info">
								  <h4 class="mb-0">Eleanor Aguilar</h4>
								  <span class="card-text">eleanor.aguilar@gmail.com</span>
								</div>
							  </div>
							</div>
						  </div>
						  <div class="d-flex justify-content-start align-items-center">
							<div class="d-flex">
								<a href="./app-user-edit.html" class="btn btn-primary btn-sm waves-effect waves-float waves-light">Edit</a> &nbsp; 
								<button class="btn btn-outline-danger ms-1 waves-effect btn-sm">Delete</button>
							</div>
					      </div>
						</div>
					  </div>
					</div>
				  </div>
				</div>
				
				
				
			 
			   </div>
			</div>
			
				<!-- END: Content-->
	
	        <button type="btn" class="btn btn-primary me-1 data-submit OpneMapping  ">Open Mapping Sidebar</button>
	
            </div>
          
      </div>
    </div>
    </div>
 </section>
<!--/ Basic table -->


        </div>
    
			
			
			
@endsection
@push('page-script')  

    <!-- BEGIN: Page Vendor JS-->
    <script src="https://esb.apiworx.net/integration/public/esb_asset/vendors/js/tables/datatable/jquery.dataTables.min.js"></script>
    <script src="https://esb.apiworx.net/integration/public/esb_asset/vendors/js/tables/datatable/dataTables.bootstrap5.min.js"></script>
    <script src="https://esb.apiworx.net/integration/public/esb_asset/vendors/js/tables/datatable/dataTables.responsive.min.js"></script>
    <script src="https://esb.apiworx.net/integration/public/esb_asset/vendors/js/tables/datatable/responsive.bootstrap4.min.js"></script>
    <script src="https://esb.apiworx.net/integration/public/esb_asset/vendors/js/tables/datatable/datatables.checkboxes.min.js"></script>
    <script src="https://esb.apiworx.net/integration/public/esb_asset/vendors/js/tables/datatable/datatables.buttons.min.js"></script>
    <script src="https://esb.apiworx.net/integration/public/esb_asset/vendors/js/tables/datatable/jszip.min.js"></script>
    <script src="https://esb.apiworx.net/integration/public/esb_asset/vendors/js/tables/datatable/pdfmake.min.js"></script>
    <script src="https://esb.apiworx.net/integration/public/esb_asset/vendors/js/tables/datatable/vfs_fonts.js"></script>
    <script src="https://esb.apiworx.net/integration/public/esb_asset/vendors/js/tables/datatable/buttons.html5.min.js"></script>
    <script src="https://esb.apiworx.net/integration/public/esb_asset/vendors/js/tables/datatable/buttons.print.min.js"></script>
    <script src="https://esb.apiworx.net/integration/public/esb_asset/vendors/js/tables/datatable/dataTables.rowGroup.min.js"></script>
    <script src="https://esb.apiworx.net/integration/public/esb_asset/vendors/js/pickers/flatpickr/flatpickr.min.js"></script>

    
 
    <script src="https://esb.apiworx.net/integration/public/esb_asset/js/scripts/components/components-navs.min.js"></script>
    <!-- BEGIN: Page JS-->
    <script src="https://esb.apiworx.net/integration/public/esb_asset/js/scripts/tables/table-datatables-basic.js"></script>
    <script src="https://esb.apiworx.net/integration/public/esb_asset/js/scripts/pages/app-user-list.min.js"></script>
    <!-- END: Page JS-->  
	
	<script>
	  jQuery('.nav-tabs li a.nav-link').click(function(e){
		  //alert("ok");
		  event.preventDefault();
		  
		  jQuery(this).parents('.nav-tabs').find('.nav-link').removeClass("active");
		  jQuery(this).addClass("active");
		  
		 const tabId = jQuery(this).attr('id');
		 
		 jQuery(this).parents('.nav-tabs').next('.tab-content').find('.tab-pane').removeClass("active");
		 jQuery(this).parents('.nav-tabs').next('.tab-content').find('.tab-pane[aria-labelledby='+tabId+']').addClass("active");
		 
	  });
	  
	  
	  jQuery('.OpneMapping').click(function(e){
		    jQuery('.mappingSidebar').toggleClass('open');
	  });
	  
	  jQuery('.customizer-close').click(function(e){
		    jQuery('.mappingSidebar').removeClass('open');
	  });
	  
	  
	  jQuery('.addBtn').click(function(e){
		  
		const tabId = jQuery('.D_MainItem').html();
		jQuery( ".dynemicMapping" ).append( '<div class="row text-center mx-0 mb-1 justify-content-center align-items-center DItem">'+tabId+'</div>' );
		 
		 
	  });
	
	  jQuery(document).on('click','.removeBtn', function(e){
		 jQuery(this).parents('.DItem').remove();
	  });
	  
	  
	</script>
	
@endpush
