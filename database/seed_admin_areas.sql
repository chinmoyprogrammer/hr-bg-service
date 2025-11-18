-- Seed admin areas (divisions, districts, upazilas)
-- Charset and transaction safety
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;
START TRANSACTION;

-- Divisions
INSERT INTO divisions (
  id,
  created_user_id,
  updated_user_id,
  name,
  name_bn,
  child_data_identifier_key_incoming,
  child_data_identifier_key_outgoing,
  status,
  created_at,
  updated_at,
  deleted_by,
  deleted_at
) VALUES
  (1, 1, NULL, 'Dhaka', 'ঢাকা', NULL, NULL, 1, NOW(), NULL, NULL, NULL),
  (2, 1, NULL, 'Chattogram', 'চট্টগ্রাম', NULL, NULL, 1, NOW(), NULL, NULL, NULL),
  (3, 1, NULL, 'Rajshahi', 'রাজশাহী', NULL, NULL, 1, NOW(), NULL, NULL, NULL),
  (4, 1, NULL, 'Khulna', 'খুলনা', NULL, NULL, 1, NOW(), NULL, NULL, NULL),
  (5, 1, NULL, 'Barishal', 'বরিশাল', NULL, NULL, 1, NOW(), NULL, NULL, NULL),
  (6, 1, NULL, 'Sylhet', 'সিলেট', NULL, NULL, 1, NOW(), NULL, NULL, NULL),
  (7, 1, NULL, 'Rangpur', 'রংপুর', NULL, NULL, 1, NOW(), NULL, NULL, NULL),
  (8, 1, NULL, 'Mymensingh', 'ময়মনসিংহ', NULL, NULL, 1, NOW(), NULL, NULL, NULL);

-- Districts (64)
INSERT INTO districts (
  id,
  division_id,
  name,
  name_bn,
  short_name,
  short_name_bn,
  child_data_identifier_key_incoming,
  child_data_identifier_key_outgoing,
  status,
  created_user_id,
  updated_user_id,
  created_at,
  updated_at,
  deleted_by,
  deleted_at
) VALUES
  -- Dhaka division (1)
  (1, 1, 'Dhaka', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (2, 1, 'Gazipur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (3, 1, 'Kishoreganj', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (4, 1, 'Manikganj', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (5, 1, 'Munshiganj', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (6, 1, 'Narayanganj', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (7, 1, 'Narsingdi', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (8, 1, 'Tangail', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (9, 1, 'Faridpur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (10, 1, 'Gopalganj', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (11, 1, 'Madaripur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (12, 1, 'Rajbari', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (13, 1, 'Shariatpur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Chattogram division (2)
  (14, 2, 'Bandarban', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (15, 2, 'Brahmanbaria', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (16, 2, 'Chandpur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (17, 2, 'Chattogram', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (18, 2, 'Cox''s Bazar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (19, 2, 'Cumilla', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (20, 2, 'Feni', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (21, 2, 'Khagrachhari', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (22, 2, 'Lakshmipur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (23, 2, 'Noakhali', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (24, 2, 'Rangamati', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Sylhet division (6)
  (25, 6, 'Habiganj', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (26, 6, 'Moulvibazar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (27, 6, 'Sylhet', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (28, 6, 'Sunamganj', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Barishal division (5)
  (29, 5, 'Barguna', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (30, 5, 'Barishal', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (31, 5, 'Bhola', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (32, 5, 'Jhalokathi', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (33, 5, 'Patuakhali', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (34, 5, 'Pirojpur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Khulna division (4)
  (35, 4, 'Bagerhat', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (36, 4, 'Chuadanga', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (37, 4, 'Jashore', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (38, 4, 'Jhenaidah', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (39, 4, 'Khulna', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (40, 4, 'Kushtia', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (41, 4, 'Magura', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (42, 4, 'Meherpur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (43, 4, 'Narail', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (44, 4, 'Satkhira', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Rajshahi division (3)
  (45, 3, 'Bogura', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (46, 3, 'Joypurhat', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (47, 3, 'Naogaon', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (48, 3, 'Natore', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (49, 3, 'Chapai Nawabganj', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (50, 3, 'Pabna', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (51, 3, 'Rajshahi', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (52, 3, 'Sirajganj', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Rangpur division (7)
  (53, 7, 'Dinajpur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (54, 7, 'Gaibandha', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (55, 7, 'Kurigram', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (56, 7, 'Lalmonirhat', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (57, 7, 'Nilphamari', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (58, 7, 'Panchagarh', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (59, 7, 'Rangpur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (60, 7, 'Thakurgaon', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Mymensingh division (8)
  (61, 8, 'Jamalpur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (62, 8, 'Mymensingh', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (63, 8, 'Netrokona', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (64, 8, 'Sherpur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL);

-- Upazilas
-- NOTE: The full list is large (~495+). Below are examples.
INSERT INTO upazilas (
  id,
  division_id,
  district_id,
  name,
  name_bn,
  short_name,
  short_name_bn,
  child_data_identifier_key_incoming,
  child_data_identifier_key_outgoing,
  status,
  created_user_id,
  updated_user_id,
  created_at,
  updated_at,
  deleted_by,
  deleted_at
) VALUES
  -- Dhaka district (1) | division (1)
  (1, 1, 1, 'Dhamrai', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (2, 1, 1, 'Dohar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (3, 1, 1, 'Keraniganj', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (4, 1, 1, 'Nawabganj', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (5, 1, 1, 'Savar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Gazipur district (2) | division (1)
  (6, 1, 2, 'Gazipur Sadar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (7, 1, 2, 'Kaliakair', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (8, 1, 2, 'Kapasia', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (9, 1, 2, 'Sreepur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Kishoreganj district (3) | division (1)
  (10, 1, 3, 'Austagram', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (11, 1, 3, 'Bajitpur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (12, 1, 3, 'Bhairab', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (13, 1, 3, 'Hossainpur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (14, 1, 3, 'Itna', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (15, 1, 3, 'Karimganj', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (16, 1, 3, 'Katiadi', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (17, 1, 3, 'Kishoreganj Sadar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (18, 1, 3, 'Kuliarchar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (19, 1, 3, 'Mithamain', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (20, 1, 3, 'Nikli', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (21, 1, 3, 'Pakundia', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (22, 1, 3, 'Tarail', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Manikganj district (4) | division (1)
  (23, 1, 4, 'Manikganj Sadar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (24, 1, 4, 'Singair', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (25, 1, 4, 'Saturia', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (26, 1, 4, 'Shivalaya', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (27, 1, 4, 'Harirampur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (28, 1, 4, 'Ghior', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (29, 1, 4, 'Daulatpur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL);

-- Dhaka division continued
INSERT INTO upazilas (
  id,
  division_id,
  district_id,
  name,
  name_bn,
  short_name,
  short_name_bn,
  child_data_identifier_key_incoming,
  child_data_identifier_key_outgoing,
  status,
  created_user_id,
  updated_user_id,
  created_at,
  updated_at,
  deleted_by,
  deleted_at
) VALUES
  -- Munshiganj district (5)
  (30, 1, 5, 'Munshiganj Sadar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (31, 1, 5, 'Sreenagar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (32, 1, 5, 'Louhajang', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (33, 1, 5, 'Tongibari', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (34, 1, 5, 'Gazaria', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (35, 1, 5, 'Sirajdikhan', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Narayanganj district (6)
  (36, 1, 6, 'Narayanganj Sadar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (37, 1, 6, 'Bandar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (38, 1, 6, 'Rupganj', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (39, 1, 6, 'Sonargaon', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (40, 1, 6, 'Araihazar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Narsingdi district (7)
  (41, 1, 7, 'Narsingdi Sadar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (42, 1, 7, 'Belabo', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (43, 1, 7, 'Monohardi', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (44, 1, 7, 'Palash', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (45, 1, 7, 'Raipura', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (46, 1, 7, 'Shibpur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Tangail district (8)
  (47, 1, 8, 'Tangail Sadar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (48, 1, 8, 'Basail', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (49, 1, 8, 'Bhuapur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (50, 1, 8, 'Delduar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (51, 1, 8, 'Dhanbari', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (52, 1, 8, 'Ghatail', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (53, 1, 8, 'Gopalpur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (54, 1, 8, 'Kalihati', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (55, 1, 8, 'Madhupur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (56, 1, 8, 'Mirzapur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (57, 1, 8, 'Nagarpur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (58, 1, 8, 'Sakhipur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Faridpur district (9)
  (59, 1, 9, 'Faridpur Sadar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (60, 1, 9, 'Alfadanga', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (61, 1, 9, 'Boalmari', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (62, 1, 9, 'Madhukhali', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (63, 1, 9, 'Nagarkanda', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (64, 1, 9, 'Saltha', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (65, 1, 9, 'Sadarpur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (66, 1, 9, 'Bhanga', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (67, 1, 9, 'Charbhadrasan', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Gopalganj district (10)
  (68, 1, 10, 'Gopalganj Sadar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (69, 1, 10, 'Kotalipara', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (70, 1, 10, 'Tungipara', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (71, 1, 10, 'Kashiani', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (72, 1, 10, 'Muksudpur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Madaripur district (11)
  (73, 1, 11, 'Madaripur Sadar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (74, 1, 11, 'Kalkini', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (75, 1, 11, 'Rajoir', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (76, 1, 11, 'Shibchar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Rajbari district (12)
  (77, 1, 12, 'Rajbari Sadar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (78, 1, 12, 'Baliakandi', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (79, 1, 12, 'Goalanda', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (80, 1, 12, 'Kalukhali', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (81, 1, 12, 'Pangsha', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Shariatpur district (13)
  (82, 1, 13, 'Shariatpur Sadar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (83, 1, 13, 'Naria', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (84, 1, 13, 'Zanjira', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (85, 1, 13, 'Damudya', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (86, 1, 13, 'Gosairhat', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (87, 1, 13, 'Bhedarganj', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL);

-- TODO: Fill the remaining upazilas from the provided PDF (514_Upozila_Election_Office.pdf)
-- If you want, we can auto-generate the full list from the PDF and
-- append it here to make this a complete seed file.

-- Chattogram division
INSERT INTO upazilas (
  id,
  division_id,
  district_id,
  name,
  name_bn,
  short_name,
  short_name_bn,
  child_data_identifier_key_incoming,
  child_data_identifier_key_outgoing,
  status,
  created_user_id,
  updated_user_id,
  created_at,
  updated_at,
  deleted_by,
  deleted_at
) VALUES
  -- Bandarban district (14)
  (88, 2, 14, 'Bandarban Sadar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (89, 2, 14, 'Thanchi', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (90, 2, 14, 'Lama', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (91, 2, 14, 'Naikhongchhari', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (92, 2, 14, 'Alikadam', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (93, 2, 14, 'Rowangchhari', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (94, 2, 14, 'Ruma', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Brahmanbaria district (15)
  (95, 2, 15, 'Brahmanbaria Sadar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (96, 2, 15, 'Ashuganj', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (97, 2, 15, 'Kasba', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (98, 2, 15, 'Akhaura', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (99, 2, 15, 'Nasirnagar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (100, 2, 15, 'Nabinagar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (101, 2, 15, 'Sarail', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (102, 2, 15, 'Bancharampur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (103, 2, 15, 'Bijoynagar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Chandpur district (16)
  (104, 2, 16, 'Chandpur Sadar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (105, 2, 16, 'Faridganj', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (106, 2, 16, 'Haimchar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (107, 2, 16, 'Kachua', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (108, 2, 16, 'Matlab Dakshin', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (109, 2, 16, 'Matlab Uttar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (110, 2, 16, 'Shahrasti', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (111, 2, 16, 'Hajiganj', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Chattogram district (17)
  (112, 2, 17, 'Anwara', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (113, 2, 17, 'Banshkhali', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (114, 2, 17, 'Boalkhali', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (115, 2, 17, 'Chandanaish', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (116, 2, 17, 'Fatikchhari', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (117, 2, 17, 'Hathazari', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (118, 2, 17, 'Lohagara', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (119, 2, 17, 'Mirsharai', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (120, 2, 17, 'Patiya', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (121, 2, 17, 'Rangunia', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (122, 2, 17, 'Raozan', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (123, 2, 17, 'Sandwip', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (124, 2, 17, 'Satkania', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (125, 2, 17, 'Sitakunda', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Cox's Bazar district (18)
  (126, 2, 18, 'Cox''s Bazar Sadar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (127, 2, 18, 'Ramu', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (128, 2, 18, 'Ukhia', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (129, 2, 18, 'Teknaf', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (130, 2, 18, 'Chakaria', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (131, 2, 18, 'Pekua', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (132, 2, 18, 'Maheshkhali', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (133, 2, 18, 'Kutubdia', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Cumilla district (19)
  (134, 2, 19, 'Barura', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (135, 2, 19, 'Brahmanpara', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (136, 2, 19, 'Burichong', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (137, 2, 19, 'Chandina', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (138, 2, 19, 'Chauddagram', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (139, 2, 19, 'Comilla Adarsha Sadar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (140, 2, 19, 'Comilla Sadar Dakshin', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (141, 2, 19, 'Daudkandi', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (142, 2, 19, 'Debidwar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (143, 2, 19, 'Homna', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (144, 2, 19, 'Laksam', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (145, 2, 19, 'Manoharganj', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (146, 2, 19, 'Meghna', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (147, 2, 19, 'Muradnagar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (148, 2, 19, 'Nangalkot', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (149, 2, 19, 'Titash', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (150, 2, 19, 'Lalmai', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Feni district (20)
  (151, 2, 20, 'Feni Sadar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (152, 2, 20, 'Chhagalnaiya', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (153, 2, 20, 'Daganbhuiyan', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (154, 2, 20, 'Parshuram', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (155, 2, 20, 'Sonagazi', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (156, 2, 20, 'Fulgazi', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Khagrachhari district (21)
  (157, 2, 21, 'Khagrachhari Sadar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (158, 2, 21, 'Dighinala', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (159, 2, 21, 'Panchhari', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (160, 2, 21, 'Mahalchhari', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (161, 2, 21, 'Manikchhari', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (162, 2, 21, 'Matiranga', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (163, 2, 21, 'Ramgarh', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (164, 2, 21, 'Lakshmichhari', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Lakshmipur district (22)
  (165, 2, 22, 'Lakshmipur Sadar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (166, 2, 22, 'Raipur', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (167, 2, 22, 'Ramganj', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (168, 2, 22, 'Ramgati', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (169, 2, 22, 'Kamalnagar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Noakhali district (23)
  (170, 2, 23, 'Noakhali Sadar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (171, 2, 23, 'Begumganj', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (172, 2, 23, 'Chatkhil', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (173, 2, 23, 'Companiganj', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (174, 2, 23, 'Hatiya', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (175, 2, 23, 'Senbagh', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (176, 2, 23, 'Sonaimuri', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (177, 2, 23, 'Subarnachar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (178, 2, 23, 'Kabirhat', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),

  -- Rangamati district (24)
  (179, 2, 24, 'Rangamati Sadar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (180, 2, 24, 'Baghaichhari', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (181, 2, 24, 'Barkal', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (182, 2, 24, 'Kawkhali', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (183, 2, 24, 'Langadu', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (184, 2, 24, 'Naniarchar', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (185, 2, 24, 'Rajasthali', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (186, 2, 24, 'Kaptai', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (187, 2, 24, 'Juraichhari', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL),
  (188, 2, 24, 'Belaichhari', NULL, NULL, NULL, NULL, NULL, 1, 1, NULL, NOW(), NULL, NULL, NULL);

-- Sylhet Division (division_id = 6)
-- Habiganj District (district_id = 25)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (189, 'Habiganj Sadar', NULL, NULL, 25, 6, 1, NOW(), 1),
  (190, 'Ajmiriganj', NULL, NULL, 25, 6, 1, NOW(), 1),
  (191, 'Bahubal', NULL, NULL, 25, 6, 1, NOW(), 1),
  (192, 'Baniachang', NULL, NULL, 25, 6, 1, NOW(), 1),
  (193, 'Chunarughat', NULL, NULL, 25, 6, 1, NOW(), 1),
  (194, 'Lakhai', NULL, NULL, 25, 6, 1, NOW(), 1),
  (195, 'Madhabpur', NULL, NULL, 25, 6, 1, NOW(), 1),
  (196, 'Nabiganj', NULL, NULL, 25, 6, 1, NOW(), 1),
  (197, 'Shayestaganj', NULL, NULL, 25, 6, 1, NOW(), 1);

-- Moulvibazar District (district_id = 26)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (198, 'Moulvibazar Sadar', NULL, NULL, 26, 6, 1, NOW(), 1),
  (199, 'Barlekha', NULL, NULL, 26, 6, 1, NOW(), 1),
  (200, 'Juri', NULL, NULL, 26, 6, 1, NOW(), 1),
  (201, 'Kamalganj', NULL, NULL, 26, 6, 1, NOW(), 1),
  (202, 'Kulaura', NULL, NULL, 26, 6, 1, NOW(), 1),
  (203, 'Rajnagar', NULL, NULL, 26, 6, 1, NOW(), 1),
  (204, 'Sreemangal', NULL, NULL, 26, 6, 1, NOW(), 1);

-- Sylhet District (district_id = 27)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (205, 'Sylhet Sadar', NULL, NULL, 27, 6, 1, NOW(), 1),
  (206, 'Balaganj', NULL, NULL, 27, 6, 1, NOW(), 1),
  (207, 'Beanibazar', NULL, NULL, 27, 6, 1, NOW(), 1),
  (208, 'Biswanath', NULL, NULL, 27, 6, 1, NOW(), 1),
  (209, 'Companiganj', NULL, NULL, 27, 6, 1, NOW(), 1),
  (210, 'Dakshin Surma', NULL, NULL, 27, 6, 1, NOW(), 1),
  (211, 'Fenchuganj', NULL, NULL, 27, 6, 1, NOW(), 1),
  (212, 'Golapganj', NULL, NULL, 27, 6, 1, NOW(), 1),
  (213, 'Gowainghat', NULL, NULL, 27, 6, 1, NOW(), 1),
  (214, 'Jaintapur', NULL, NULL, 27, 6, 1, NOW(), 1),
  (215, 'Kanaighat', NULL, NULL, 27, 6, 1, NOW(), 1),
  (216, 'Osmaninagar', NULL, NULL, 27, 6, 1, NOW(), 1),
  (217, 'Zakiganj', NULL, NULL, 27, 6, 1, NOW(), 1);

-- Sunamganj District (district_id = 28)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (218, 'Sunamganj Sadar', NULL, NULL, 28, 6, 1, NOW(), 1),
  (219, 'Bishwambharpur', NULL, NULL, 28, 6, 1, NOW(), 1),
  (220, 'Chhatak', NULL, NULL, 28, 6, 1, NOW(), 1),
  (221, 'Dowarabazar', NULL, NULL, 28, 6, 1, NOW(), 1),
  (222, 'Derai', NULL, NULL, 28, 6, 1, NOW(), 1),
  (223, 'Dharmapasha', NULL, NULL, 28, 6, 1, NOW(), 1),
  (224, 'Jamalganj', NULL, NULL, 28, 6, 1, NOW(), 1),
  (225, 'Jagannathpur', NULL, NULL, 28, 6, 1, NOW(), 1),
  (226, 'Sulla', NULL, NULL, 28, 6, 1, NOW(), 1),
  (227, 'Tahirpur', NULL, NULL, 28, 6, 1, NOW(), 1),
  (228, 'Shantiganj', NULL, NULL, 28, 6, 1, NOW(), 1);

-- Barishal Division (division_id = 5)
-- Barguna District (district_id = 29)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (229, 'Barguna Sadar', NULL, NULL, 29, 5, 1, NOW(), 1),
  (230, 'Amtali', NULL, NULL, 29, 5, 1, NOW(), 1),
  (231, 'Bamna', NULL, NULL, 29, 5, 1, NOW(), 1),
  (232, 'Betagi', NULL, NULL, 29, 5, 1, NOW(), 1),
  (233, 'Patharghata', NULL, NULL, 29, 5, 1, NOW(), 1),
  (234, 'Taltali', NULL, NULL, 29, 5, 1, NOW(), 1);

-- Barishal District (district_id = 30)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (235, 'Barishal Sadar', NULL, NULL, 30, 5, 1, NOW(), 1),
  (236, 'Agailjhara', NULL, NULL, 30, 5, 1, NOW(), 1),
  (237, 'Babuganj', NULL, NULL, 30, 5, 1, NOW(), 1),
  (238, 'Bakerganj', NULL, NULL, 30, 5, 1, NOW(), 1),
  (239, 'Banaripara', NULL, NULL, 30, 5, 1, NOW(), 1),
  (240, 'Gournadi', NULL, NULL, 30, 5, 1, NOW(), 1),
  (241, 'Hizla', NULL, NULL, 30, 5, 1, NOW(), 1),
  (242, 'Mehendiganj', NULL, NULL, 30, 5, 1, NOW(), 1),
  (243, 'Muladi', NULL, NULL, 30, 5, 1, NOW(), 1);

-- Bhola District (district_id = 31)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (244, 'Bhola Sadar', NULL, NULL, 31, 5, 1, NOW(), 1),
  (245, 'Borhanuddin', NULL, NULL, 31, 5, 1, NOW(), 1),
  (246, 'Char Fasson', NULL, NULL, 31, 5, 1, NOW(), 1),
  (247, 'Daulatkhan', NULL, NULL, 31, 5, 1, NOW(), 1),
  (248, 'Lalmohan', NULL, NULL, 31, 5, 1, NOW(), 1),
  (249, 'Manpura', NULL, NULL, 31, 5, 1, NOW(), 1),
  (250, 'Tazumuddin', NULL, NULL, 31, 5, 1, NOW(), 1);

-- Jhalokathi District (district_id = 32)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (251, 'Jhalokathi Sadar', NULL, NULL, 32, 5, 1, NOW(), 1),
  (252, 'Kathalia', NULL, NULL, 32, 5, 1, NOW(), 1),
  (253, 'Nalchity', NULL, NULL, 32, 5, 1, NOW(), 1),
  (254, 'Rajapur', NULL, NULL, 32, 5, 1, NOW(), 1);

-- Patuakhali District (district_id = 33)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (255, 'Patuakhali Sadar', NULL, NULL, 33, 5, 1, NOW(), 1),
  (256, 'Bauphal', NULL, NULL, 33, 5, 1, NOW(), 1),
  (257, 'Dashmina', NULL, NULL, 33, 5, 1, NOW(), 1),
  (258, 'Dumki', NULL, NULL, 33, 5, 1, NOW(), 1),
  (259, 'Galachipa', NULL, NULL, 33, 5, 1, NOW(), 1),
  (260, 'Kalapara', NULL, NULL, 33, 5, 1, NOW(), 1),
  (261, 'Mirzaganj', NULL, NULL, 33, 5, 1, NOW(), 1);

-- Pirojpur District (district_id = 34)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (262, 'Pirojpur Sadar', NULL, NULL, 34, 5, 1, NOW(), 1),
  (263, 'Bhandaria', NULL, NULL, 34, 5, 1, NOW(), 1),
  (264, 'Indurkani', NULL, NULL, 34, 5, 1, NOW(), 1),
  (265, 'Kawkhali', NULL, NULL, 34, 5, 1, NOW(), 1),
  (266, 'Mathbaria', NULL, NULL, 34, 5, 1, NOW(), 1),
  (267, 'Nazirpur', NULL, NULL, 34, 5, 1, NOW(), 1),
  (268, 'Nesarabad', NULL, NULL, 34, 5, 1, NOW(), 1);

-- Khulna Division (division_id = 4)
-- Bagerhat District (district_id = 35)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (269, 'Bagerhat Sadar', NULL, NULL, 35, 4, 1, NOW(), 1),
  (270, 'Chitalmari', NULL, NULL, 35, 4, 1, NOW(), 1),
  (271, 'Fakirhat', NULL, NULL, 35, 4, 1, NOW(), 1),
  (272, 'Kachua', NULL, NULL, 35, 4, 1, NOW(), 1),
  (273, 'Mollahat', NULL, NULL, 35, 4, 1, NOW(), 1),
  (274, 'Mongla', NULL, NULL, 35, 4, 1, NOW(), 1),
  (275, 'Morrelganj', NULL, NULL, 35, 4, 1, NOW(), 1),
  (276, 'Rampal', NULL, NULL, 35, 4, 1, NOW(), 1),
  (277, 'Sharankhola', NULL, NULL, 35, 4, 1, NOW(), 1);

-- Chuadanga District (district_id = 36)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (278, 'Chuadanga Sadar', NULL, NULL, 36, 4, 1, NOW(), 1),
  (279, 'Alamdanga', NULL, NULL, 36, 4, 1, NOW(), 1),
  (280, 'Damurhuda', NULL, NULL, 36, 4, 1, NOW(), 1),
  (281, 'Jibannagar', NULL, NULL, 36, 4, 1, NOW(), 1);

-- Jashore District (district_id = 37)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (282, 'Jashore Sadar', NULL, NULL, 37, 4, 1, NOW(), 1),
  (283, 'Abhaynagar', NULL, NULL, 37, 4, 1, NOW(), 1),
  (284, 'Bagherpara', NULL, NULL, 37, 4, 1, NOW(), 1),
  (285, 'Chaugachha', NULL, NULL, 37, 4, 1, NOW(), 1),
  (286, 'Jhikargacha', NULL, NULL, 37, 4, 1, NOW(), 1),
  (287, 'Keshabpur', NULL, NULL, 37, 4, 1, NOW(), 1),
  (288, 'Manirampur', NULL, NULL, 37, 4, 1, NOW(), 1),
  (289, 'Sharsha', NULL, NULL, 37, 4, 1, NOW(), 1);

-- Jhenaidah District (district_id = 38)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (290, 'Jhenaidah Sadar', NULL, NULL, 38, 4, 1, NOW(), 1),
  (291, 'Harinakunda', NULL, NULL, 38, 4, 1, NOW(), 1),
  (292, 'Kaliganj', NULL, NULL, 38, 4, 1, NOW(), 1),
  (293, 'Kotchandpur', NULL, NULL, 38, 4, 1, NOW(), 1),
  (294, 'Maheshpur', NULL, NULL, 38, 4, 1, NOW(), 1),
  (295, 'Shailkupa', NULL, NULL, 38, 4, 1, NOW(), 1);

-- Khulna District (district_id = 39)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (296, 'Batiaghata', NULL, NULL, 39, 4, 1, NOW(), 1),
  (297, 'Dacope', NULL, NULL, 39, 4, 1, NOW(), 1),
  (298, 'Dighalia', NULL, NULL, 39, 4, 1, NOW(), 1),
  (299, 'Dumuria', NULL, NULL, 39, 4, 1, NOW(), 1),
  (300, 'Koyra', NULL, NULL, 39, 4, 1, NOW(), 1),
  (301, 'Paikgachha', NULL, NULL, 39, 4, 1, NOW(), 1),
  (302, 'Phultala', NULL, NULL, 39, 4, 1, NOW(), 1),
  (303, 'Rupsha', NULL, NULL, 39, 4, 1, NOW(), 1),
  (304, 'Terokhada', NULL, NULL, 39, 4, 1, NOW(), 1);

-- Kushtia District (district_id = 40)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (305, 'Kushtia Sadar', NULL, NULL, 40, 4, 1, NOW(), 1),
  (306, 'Bheramara', NULL, NULL, 40, 4, 1, NOW(), 1),
  (307, 'Daulatpur', NULL, NULL, 40, 4, 1, NOW(), 1),
  (308, 'Khoksa', NULL, NULL, 40, 4, 1, NOW(), 1),
  (309, 'Kumarkhali', NULL, NULL, 40, 4, 1, NOW(), 1),
  (310, 'Mirpur', NULL, NULL, 40, 4, 1, NOW(), 1);

-- Magura District (district_id = 41)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (311, 'Magura Sadar', NULL, NULL, 41, 4, 1, NOW(), 1),
  (312, 'Mohammadpur', NULL, NULL, 41, 4, 1, NOW(), 1),
  (313, 'Shalikha', NULL, NULL, 41, 4, 1, NOW(), 1),
  (314, 'Sreepur', NULL, NULL, 41, 4, 1, NOW(), 1);

-- Meherpur District (district_id = 42)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (315, 'Meherpur Sadar', NULL, NULL, 42, 4, 1, NOW(), 1),
  (316, 'Mujibnagar', NULL, NULL, 42, 4, 1, NOW(), 1),
  (317, 'Gangni', NULL, NULL, 42, 4, 1, NOW(), 1);

-- Narail District (district_id = 43)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (318, 'Narail Sadar', NULL, NULL, 43, 4, 1, NOW(), 1),
  (319, 'Kalia', NULL, NULL, 43, 4, 1, NOW(), 1),
  (320, 'Lohagara', NULL, NULL, 43, 4, 1, NOW(), 1);

-- Satkhira District (district_id = 44)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (321, 'Satkhira Sadar', NULL, NULL, 44, 4, 1, NOW(), 1),
  (322, 'Assasuni', NULL, NULL, 44, 4, 1, NOW(), 1),
  (323, 'Debhata', NULL, NULL, 44, 4, 1, NOW(), 1),
  (324, 'Kalaroa', NULL, NULL, 44, 4, 1, NOW(), 1),
  (325, 'Kaliganj', NULL, NULL, 44, 4, 1, NOW(), 1),
  (326, 'Shyamnagar', NULL, NULL, 44, 4, 1, NOW(), 1),
  (327, 'Tala', NULL, NULL, 44, 4, 1, NOW(), 1);

-- Rajshahi Division (division_id = 3)
-- Bogura District (district_id = 45)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (328, 'Bogura Sadar', NULL, NULL, 45, 3, 1, NOW(), 1),
  (329, 'Adamdighi', NULL, NULL, 45, 3, 1, NOW(), 1),
  (330, 'Dhupchanchia', NULL, NULL, 45, 3, 1, NOW(), 1),
  (331, 'Gabtali', NULL, NULL, 45, 3, 1, NOW(), 1),
  (332, 'Kahaloo', NULL, NULL, 45, 3, 1, NOW(), 1),
  (333, 'Nandigram', NULL, NULL, 45, 3, 1, NOW(), 1),
  (334, 'Shajahanpur', NULL, NULL, 45, 3, 1, NOW(), 1),
  (335, 'Sariakandi', NULL, NULL, 45, 3, 1, NOW(), 1),
  (336, 'Sherpur', NULL, NULL, 45, 3, 1, NOW(), 1),
  (337, 'Shibganj', NULL, NULL, 45, 3, 1, NOW(), 1),
  (338, 'Sonatala', NULL, NULL, 45, 3, 1, NOW(), 1);

-- Joypurhat District (district_id = 46)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (339, 'Joypurhat Sadar', NULL, NULL, 46, 3, 1, NOW(), 1),
  (340, 'Akkelpur', NULL, NULL, 46, 3, 1, NOW(), 1),
  (341, 'Kalai', NULL, NULL, 46, 3, 1, NOW(), 1),
  (342, 'Khetlal', NULL, NULL, 46, 3, 1, NOW(), 1),
  (343, 'Panchbibi', NULL, NULL, 46, 3, 1, NOW(), 1);

-- Naogaon District (district_id = 47)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (344, 'Naogaon Sadar', NULL, NULL, 47, 3, 1, NOW(), 1),
  (345, 'Atrai', NULL, NULL, 47, 3, 1, NOW(), 1),
  (346, 'Badalgachhi', NULL, NULL, 47, 3, 1, NOW(), 1),
  (347, 'Dhamoirhat', NULL, NULL, 47, 3, 1, NOW(), 1),
  (348, 'Mahadebpur', NULL, NULL, 47, 3, 1, NOW(), 1),
  (349, 'Manda', NULL, NULL, 47, 3, 1, NOW(), 1),
  (350, 'Niamatpur', NULL, NULL, 47, 3, 1, NOW(), 1),
  (351, 'Patnitala', NULL, NULL, 47, 3, 1, NOW(), 1),
  (352, 'Porsha', NULL, NULL, 47, 3, 1, NOW(), 1),
  (353, 'Raninagar', NULL, NULL, 47, 3, 1, NOW(), 1),
  (354, 'Sapahar', NULL, NULL, 47, 3, 1, NOW(), 1);

-- Natore District (district_id = 48)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (355, 'Natore Sadar', NULL, NULL, 48, 3, 1, NOW(), 1),
  (356, 'Bagatipara', NULL, NULL, 48, 3, 1, NOW(), 1),
  (357, 'Baraigram', NULL, NULL, 48, 3, 1, NOW(), 1),
  (358, 'Gurudaspur', NULL, NULL, 48, 3, 1, NOW(), 1),
  (359, 'Lalpur', NULL, NULL, 48, 3, 1, NOW(), 1),
  (360, 'Naldanga', NULL, NULL, 48, 3, 1, NOW(), 1);

-- Chapai Nawabganj District (district_id = 49)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (361, 'Chapai Nawabganj Sadar', NULL, NULL, 49, 3, 1, NOW(), 1),
  (362, 'Bholahat', NULL, NULL, 49, 3, 1, NOW(), 1),
  (363, 'Gomastapur', NULL, NULL, 49, 3, 1, NOW(), 1),
  (364, 'Nachole', NULL, NULL, 49, 3, 1, NOW(), 1),
  (365, 'Shibganj', NULL, NULL, 49, 3, 1, NOW(), 1);

-- Pabna District (district_id = 50)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (366, 'Pabna Sadar', NULL, NULL, 50, 3, 1, NOW(), 1),
  (367, 'Atgharia', NULL, NULL, 50, 3, 1, NOW(), 1),
  (368, 'Bera', NULL, NULL, 50, 3, 1, NOW(), 1),
  (369, 'Chatmohar', NULL, NULL, 50, 3, 1, NOW(), 1),
  (370, 'Faridpur', NULL, NULL, 50, 3, 1, NOW(), 1),
  (371, 'Ishwardi', NULL, NULL, 50, 3, 1, NOW(), 1),
  (372, 'Santhia', NULL, NULL, 50, 3, 1, NOW(), 1),
  (373, 'Sujanagar', NULL, NULL, 50, 3, 1, NOW(), 1);

-- Rajshahi District (district_id = 51)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (374, 'Bagmara', NULL, NULL, 51, 3, 1, NOW(), 1),
  (375, 'Charghat', NULL, NULL, 51, 3, 1, NOW(), 1),
  (376, 'Durgapur', NULL, NULL, 51, 3, 1, NOW(), 1),
  (377, 'Godagari', NULL, NULL, 51, 3, 1, NOW(), 1),
  (378, 'Mohanpur', NULL, NULL, 51, 3, 1, NOW(), 1),
  (379, 'Paba', NULL, NULL, 51, 3, 1, NOW(), 1),
  (380, 'Puthia', NULL, NULL, 51, 3, 1, NOW(), 1),
  (381, 'Tanore', NULL, NULL, 51, 3, 1, NOW(), 1);

-- Sirajganj District (district_id = 52)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (382, 'Sirajganj Sadar', NULL, NULL, 52, 3, 1, NOW(), 1),
  (383, 'Belkuchi', NULL, NULL, 52, 3, 1, NOW(), 1),
  (384, 'Chauhali', NULL, NULL, 52, 3, 1, NOW(), 1),
  (385, 'Kamarkhanda', NULL, NULL, 52, 3, 1, NOW(), 1),
  (386, 'Kazipur', NULL, NULL, 52, 3, 1, NOW(), 1),
  (387, 'Raiganj', NULL, NULL, 52, 3, 1, NOW(), 1),
  (388, 'Shahjadpur', NULL, NULL, 52, 3, 1, NOW(), 1),
  (389, 'Tarash', NULL, NULL, 52, 3, 1, NOW(), 1),
  (390, 'Ullahpara', NULL, NULL, 52, 3, 1, NOW(), 1);

-- Rangpur Division (division_id = 7)
-- Dinajpur District (district_id = 53)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (391, 'Dinajpur Sadar', NULL, NULL, 53, 7, 1, NOW(), 1),
  (392, 'Birampur', NULL, NULL, 53, 7, 1, NOW(), 1),
  (393, 'Birganj', NULL, NULL, 53, 7, 1, NOW(), 1),
  (394, 'Birol', NULL, NULL, 53, 7, 1, NOW(), 1),
  (395, 'Bochaganj', NULL, NULL, 53, 7, 1, NOW(), 1),
  (396, 'Chirirbandar', NULL, NULL, 53, 7, 1, NOW(), 1),
  (397, 'Fulbari', NULL, NULL, 53, 7, 1, NOW(), 1),
  (398, 'Ghoraghat', NULL, NULL, 53, 7, 1, NOW(), 1),
  (399, 'Hakimpur', NULL, NULL, 53, 7, 1, NOW(), 1),
  (400, 'Kaharole', NULL, NULL, 53, 7, 1, NOW(), 1),
  (401, 'Khansama', NULL, NULL, 53, 7, 1, NOW(), 1),
  (402, 'Nawabganj', NULL, NULL, 53, 7, 1, NOW(), 1),
  (403, 'Parbatipur', NULL, NULL, 53, 7, 1, NOW(), 1);

-- Gaibandha District (district_id = 54)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (404, 'Gaibandha Sadar', NULL, NULL, 54, 7, 1, NOW(), 1),
  (405, 'Fulchhari', NULL, NULL, 54, 7, 1, NOW(), 1),
  (406, 'Gobindaganj', NULL, NULL, 54, 7, 1, NOW(), 1),
  (407, 'Palashbari', NULL, NULL, 54, 7, 1, NOW(), 1),
  (408, 'Sadullapur', NULL, NULL, 54, 7, 1, NOW(), 1),
  (409, 'Saghata', NULL, NULL, 54, 7, 1, NOW(), 1),
  (410, 'Sundarganj', NULL, NULL, 54, 7, 1, NOW(), 1);

-- Kurigram District (district_id = 55)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (411, 'Kurigram Sadar', NULL, NULL, 55, 7, 1, NOW(), 1),
  (412, 'Bhurungamari', NULL, NULL, 55, 7, 1, NOW(), 1),
  (413, 'Chilmari', NULL, NULL, 55, 7, 1, NOW(), 1),
  (414, 'Nageshwari', NULL, NULL, 55, 7, 1, NOW(), 1),
  (415, 'Phulbari', NULL, NULL, 55, 7, 1, NOW(), 1),
  (416, 'Rajarhat', NULL, NULL, 55, 7, 1, NOW(), 1),
  (417, 'Rowmari', NULL, NULL, 55, 7, 1, NOW(), 1),
  (418, 'Ulipur', NULL, NULL, 55, 7, 1, NOW(), 1),
  (419, 'Char Rajibpur', NULL, NULL, 55, 7, 1, NOW(), 1);

-- Lalmonirhat District (district_id = 56)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (420, 'Lalmonirhat Sadar', NULL, NULL, 56, 7, 1, NOW(), 1),
  (421, 'Aditmari', NULL, NULL, 56, 7, 1, NOW(), 1),
  (422, 'Kaliganj', NULL, NULL, 56, 7, 1, NOW(), 1),
  (423, 'Hatibandha', NULL, NULL, 56, 7, 1, NOW(), 1),
  (424, 'Patgram', NULL, NULL, 56, 7, 1, NOW(), 1);

-- Nilphamari District (district_id = 57)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (425, 'Nilphamari Sadar', NULL, NULL, 57, 7, 1, NOW(), 1),
  (426, 'Dimla', NULL, NULL, 57, 7, 1, NOW(), 1),
  (427, 'Domar', NULL, NULL, 57, 7, 1, NOW(), 1),
  (428, 'Jaldhaka', NULL, NULL, 57, 7, 1, NOW(), 1),
  (429, 'Kishoreganj', NULL, NULL, 57, 7, 1, NOW(), 1),
  (430, 'Saidpur', NULL, NULL, 57, 7, 1, NOW(), 1);

-- Panchagarh District (district_id = 58)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (431, 'Panchagarh Sadar', NULL, NULL, 58, 7, 1, NOW(), 1),
  (432, 'Atwari', NULL, NULL, 58, 7, 1, NOW(), 1),
  (433, 'Boda', NULL, NULL, 58, 7, 1, NOW(), 1),
  (434, 'Debiganj', NULL, NULL, 58, 7, 1, NOW(), 1),
  (435, 'Tetulia', NULL, NULL, 58, 7, 1, NOW(), 1);

-- Rangpur District (district_id = 59)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (436, 'Rangpur Sadar', NULL, NULL, 59, 7, 1, NOW(), 1),
  (437, 'Badarganj', NULL, NULL, 59, 7, 1, NOW(), 1),
  (438, 'Gangachara', NULL, NULL, 59, 7, 1, NOW(), 1),
  (439, 'Kaunia', NULL, NULL, 59, 7, 1, NOW(), 1),
  (440, 'Mithapukur', NULL, NULL, 59, 7, 1, NOW(), 1),
  (441, 'Pirgachha', NULL, NULL, 59, 7, 1, NOW(), 1),
  (442, 'Pirganj', NULL, NULL, 59, 7, 1, NOW(), 1),
  (443, 'Taraganj', NULL, NULL, 59, 7, 1, NOW(), 1);

-- Thakurgaon District (district_id = 60)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (444, 'Thakurgaon Sadar', NULL, NULL, 60, 7, 1, NOW(), 1),
  (445, 'Baliadangi', NULL, NULL, 60, 7, 1, NOW(), 1),
  (446, 'Haripur', NULL, NULL, 60, 7, 1, NOW(), 1),
  (447, 'Pirganj', NULL, NULL, 60, 7, 1, NOW(), 1),
  (448, 'Ranishankail', NULL, NULL, 60, 7, 1, NOW(), 1);

-- Mymensingh Division (division_id = 8)
-- Jamalpur District (district_id = 61)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (449, 'Jamalpur Sadar', NULL, NULL, 61, 8, 1, NOW(), 1),
  (450, 'Bakshiganj', NULL, NULL, 61, 8, 1, NOW(), 1),
  (451, 'Dewanganj', NULL, NULL, 61, 8, 1, NOW(), 1),
  (452, 'Islampur', NULL, NULL, 61, 8, 1, NOW(), 1),
  (453, 'Madarganj', NULL, NULL, 61, 8, 1, NOW(), 1),
  (454, 'Melandaha', NULL, NULL, 61, 8, 1, NOW(), 1),
  (455, 'Sarishabari', NULL, NULL, 61, 8, 1, NOW(), 1);

-- Mymensingh District (district_id = 62)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (456, 'Mymensingh Sadar', NULL, NULL, 62, 8, 1, NOW(), 1),
  (457, 'Bhaluka', NULL, NULL, 62, 8, 1, NOW(), 1),
  (458, 'Dhobaura', NULL, NULL, 62, 8, 1, NOW(), 1),
  (459, 'Fulbaria', NULL, NULL, 62, 8, 1, NOW(), 1),
  (460, 'Gaffargaon', NULL, NULL, 62, 8, 1, NOW(), 1),
  (461, 'Gouripur', NULL, NULL, 62, 8, 1, NOW(), 1),
  (462, 'Haluaghat', NULL, NULL, 62, 8, 1, NOW(), 1),
  (463, 'Ishwarganj', NULL, NULL, 62, 8, 1, NOW(), 1),
  (464, 'Muktagachha', NULL, NULL, 62, 8, 1, NOW(), 1),
  (465, 'Nandail', NULL, NULL, 62, 8, 1, NOW(), 1),
  (466, 'Phulpur', NULL, NULL, 62, 8, 1, NOW(), 1),
  (467, 'Trishal', NULL, NULL, 62, 8, 1, NOW(), 1),
  (468, 'Tarakanda', NULL, NULL, 62, 8, 1, NOW(), 1);

-- Netrokona District (district_id = 63)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (469, 'Netrokona Sadar', NULL, NULL, 63, 8, 1, NOW(), 1),
  (470, 'Atpara', NULL, NULL, 63, 8, 1, NOW(), 1),
  (471, 'Barhatta', NULL, NULL, 63, 8, 1, NOW(), 1),
  (472, 'Durgapur', NULL, NULL, 63, 8, 1, NOW(), 1),
  (473, 'Kalmakanda', NULL, NULL, 63, 8, 1, NOW(), 1),
  (474, 'Kendua', NULL, NULL, 63, 8, 1, NOW(), 1),
  (475, 'Madan', NULL, NULL, 63, 8, 1, NOW(), 1),
  (476, 'Mohanganj', NULL, NULL, 63, 8, 1, NOW(), 1),
  (477, 'Purbadhala', NULL, NULL, 63, 8, 1, NOW(), 1);

-- Sherpur District (district_id = 64)
INSERT INTO `upazilas` (`id`, `name`, `name_bn`, `short_name`, `district_id`, `division_id`, `created_user_id`, `created_at`, `status`) VALUES
  (478, 'Sherpur Sadar', NULL, NULL, 64, 8, 1, NOW(), 1),
  (479, 'Jhenaigati', NULL, NULL, 64, 8, 1, NOW(), 1),
  (480, 'Nakla', NULL, NULL, 64, 8, 1, NOW(), 1),
  (481, 'Nalitabari', NULL, NULL, 64, 8, 1, NOW(), 1),
  (482, 'Sreebardi', NULL, NULL, 64, 8, 1, NOW(), 1);

COMMIT;
SET FOREIGN_KEY_CHECKS=1;
