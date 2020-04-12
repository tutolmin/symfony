#!/usr/bin/bash

types=(checkmate stalemate 1-0 0-1)
sides=(BlackSide WhiteSide)
depths=(18 23)
atypes=(fast deep)
statuses=(Pending Processing Skipped Partially Evaluated Complete)

random_side () {

  size=${#sides[*]}
  key=$((RANDOM % $size))

  side=${sides[$key]}
}

random_depth () {

  size=${#depths[*]}
  key=$((RANDOM % $size))

  depth=${depths[$key]}
}

random_type () {

  size=${#types[*]}
  key=$((RANDOM % $size))

  type=${types[$key]}
}

random_atype () {

  size=${#atypes[*]}
  key=$((RANDOM % $size))

  atype=${atypes[$key]}
}

random_status () {

  size=${#statuses[*]}
  key=$((RANDOM % $size))

  status=${statuses[$key]}
}

queue_add () {

 random_type
 random_depth
 random_side

 php bin/console queue:add:random --side=$side --type=$type --depth=$depth
}

queue_promote () {

 php bin/console queue:promote:random
}

queue_erase () {

 php bin/console queue:erase:random
}

queue_fill () {

 random_type
 random_depth
 random_side

 number=$((45 + RANDOM % 10))

 php bin/console queue:fill --threshold=$number --side=$side --type=$type --depth=$depth
}

change_status () {

 if [[ $# -eq 0 ]]; then

  random_status

 else

  status=$1

 fi

 get_queue_length "$status"
 echo "Total $status nodes: $queue_length"

 php bin/console queue:change:random status $status
}

change_side () {

 random_side

 php bin/console queue:change:random side $side
}

change_type () {

 random_atype

 php bin/console queue:change:random type $atype
}

get_queue_length () {

 queue_length=`php bin/console queue:length --status=$1 | awk '{print $7}'`

}

# Forever cycle
while [[ true ]]; do

# Fill the queue with some items
queue_fill

get_queue_length "Pending"
echo "Total Pending nodes: $queue_length"

while [[ $queue_length -gt 15 ]]; do

 change_status "Processing"
 queue_add
 queue_promote
 change_status "Evaluated"

 change_status "Processing"
 queue_add
 change_side
 change_status "Evaluated"

 change_status "Processing"
 queue_add
 change_status
 change_status "Evaluated"

 change_status "Processing"
 queue_add
 change_type
 change_status "Evaluated"

 change_status "Processing"
 queue_add
 queue_erase
 change_status "Evaluated"

 get_queue_length "Pending"
 echo
 echo "Total Pending nodes: $queue_length"
 echo

done

echo "Waiting for interruption"
sleep 60

done

<< 'MULTILINE-COMMENT'

php bin/console queue:fill --threshold=50 --side=BlackSide --type=0-1 --depth=23
php bin/console queue:fill --threshold=50 --side=BlackSide --type=0-1 --depth=18
php bin/console queue:fill --threshold=50 --side=WhiteSide --type=1-0 --depth=23
php bin/console queue:fill --threshold=50 --side=WhiteSide --type=1-0 --depth=18

php bin/console queue:promote:random
php bin/console queue:erase:random

php bin/console queue:add:random
php bin/console queue:add:random --type=checkmate
php bin/console queue:add:random --type=stalemate
php bin/console queue:add:random --depth=18
php bin/console queue:add:random --depth=23
php bin/console queue:add:random --side=BlackSide
php bin/console queue:add:random --side=WhiteSide

php bin/console queue:change:random status Processing
php bin/console queue:change:random status Evaluated
php bin/console queue:change:random status Pending

MULTILINE-COMMENT

